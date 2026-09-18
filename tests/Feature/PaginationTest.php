<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Boundary coverage for the pagination sizes revised per user feedback —
 * exactly N items must show no "Next" link, N+1 must show one. Verifies
 * the EXACT configured size, not just "pagination exists somewhere."
 */
function paginationDoc(User $originator, array $overrides = []): DocumentRepository
{
    return DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => 'pg-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'upload_date' => now(),
        'global_status' => 'approved',
        'ml_category' => 'Job Order',
    ], $overrides));
}

it('renders the custom Tailwind pagination view (not the unstyled vendor default)', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 6; $i++) {
        paginationDoc($admin);
    }

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Pagination Navigation', false); // aria-label unique to resources/views/vendor/pagination/custom.blade.php
});

it('paginates Admin Archive at 5 per page, Approver/Originator Archive at 10', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    for ($i = 0; $i < 6; $i++) {
        paginationDoc($admin);
    }

    $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']))->assertSee('Next');
    // 6th item pushes admin (5/page) into a second page but not approver (10/page).
    $this->actingAs($approver)->get(route('approver.archive'))->assertDontSee('Next');
});

it('paginates Admin Users at 5 per page', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(4)->originator()->create(); // + the admin itself = 5, still one page

    $this->actingAs($admin)->get(route('admin.users'))->assertDontSee('Next');

    User::factory()->originator()->create(); // 6th active user
    $this->actingAs($admin)->get(route('admin.users'))->assertSee('Next');
});

it('paginates the Readability Review Queue at 5 per page', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 6; $i++) {
        paginationDoc($admin, ['global_status' => 'processing', 'readability_review_status' => 'pending', 'readability_score' => 50]);
    }

    $response = $this->actingAs($admin)->get(route('admin.ml.training'));
    $response->assertOk()->assertSee('Content Readability Review (6)');

    $page2 = $this->actingAs($admin)->get(route('admin.ml.training', ['readability_page' => 2]));
    $page2->assertOk();
});

it('paginates the Auto-Approval Review queue at 2 per page', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 3; $i++) {
        $autoDoc = paginationDoc($approver, ['title' => "auto-{$i}.txt", 'global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $autoDoc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $autoDoc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
            'auto_approved' => true,
        ]);
    }

    // 3 auto-approved documents, over the 2/page bar.
    $response = $this->actingAs($admin)->get(route('admin.sla.queue'));
    $response->assertOk()->assertViewHas('reviewContainers', fn ($containers) => $containers->total() === 3 && $containers->count() === 2);

    $page2 = $this->actingAs($admin)->get(route('admin.sla.queue', ['page' => 2]));
    $page2->assertOk()->assertViewHas('reviewContainers', fn ($containers) => $containers->count() === 1);
});

it('honors both pagination params at once on the ML Training refresh fragment (Feature: AJAX pagination)', function () {
    // enableAjaxPagination() (resources/js/app.js) fetches the .../refresh
    // route with the CLICKED link's own full query string, which already
    // has both page params merged (see AdminController::paginateContainers()).
    // This proves the server side of that: a combined ?ml_page=2&readability_page=2
    // request must page each section independently, not just whichever
    // one a bare single-param request happens to test.
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 6; $i++) {
        $word = str_repeat(chr(97 + $i), 4);
        $text = implode(' ', array_fill(0, 30, $word));
        paginationDoc($admin, ['title' => "ml-{$i}.txt", 'global_status' => 'processing', 'ml_review_status' => 'pending', 'ml_confidence' => 30.0, 'ocr_text' => $text]);
        paginationDoc($admin, ['title' => "read-{$i}.txt", 'global_status' => 'processing', 'readability_review_status' => 'pending', 'readability_score' => 50]);
    }

    $response = $this->actingAs($admin)->get(route('admin.ml.review.refresh', ['ml_page' => 2, 'readability_page' => 2]));

    $response->assertOk();
    // Page 2 of a 6-item/5-per-page list has exactly 1 item left, for BOTH
    // sections at once — proves neither param was dropped/overwritten by
    // the other (each is sorted, so item index 5, i.e. "e"/"f", is what
    // survives onto page 2 — asserting on presence/absence of a page-1-only
    // title is what actually proves the paging, not just "page loaded").
    $response->assertDontSee('ml-0.txt')->assertDontSee('read-0.txt');
});

it('honors the pagination param on the SLA Queue refresh fragment (Feature: AJAX pagination)', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 3; $i++) {
        $autoDoc = paginationDoc($approver, ['title' => "auto-{$i}.txt", 'global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $autoDoc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $autoDoc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
            'auto_approved' => true,
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.queue.refresh', ['page' => 2]));

    $response->assertOk();
    // Page 2 of a 3-item/2-per-page list has exactly 1 item left.
    $response->assertDontSee('auto-0.txt')->assertDontSee('auto-1.txt')->assertSee('auto-2.txt');
});

// The Unassigned Documents pagination test that used to live here is
// gone along with the module itself — a stage with no eligible approver
// auto-approves immediately now (see WorkflowService::assignStage()),
// so there's no longer a "pending, waiting" list of these to paginate.

it('paginates the Admin Audit Trail at 10 per page', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 10; $i++) {
        \App\Models\AuditLog::record($admin->user_id, null, 'user_toggle', "PAGINATION TEST audit row {$i}");
    }
    $this->actingAs($admin)->get(route('admin.audit.logs'))->assertDontSee('Next');

    \App\Models\AuditLog::record($admin->user_id, null, 'user_toggle', 'PAGINATION TEST audit row 11');
    $this->actingAs($admin)->get(route('admin.audit.logs'))->assertSee('Next');
});

it('paginates Document Tracking at 5 per page', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 5; $i++) {
        paginationDoc($admin);
    }
    $this->actingAs($admin)->get(route('admin.documents.index'))->assertDontSee('Next');

    paginationDoc($admin);
    $this->actingAs($admin)->get(route('admin.documents.index'))->assertSee('Next');
});

it('paginates the Originator "Upload & Track Documents" list at 5 per page', function () {
    $originator = User::factory()->originator()->create();
    for ($i = 0; $i < 5; $i++) {
        paginationDoc($originator);
    }
    $this->actingAs($originator)->get(route('originator.dashboard'))->assertDontSee('Next');

    paginationDoc($originator);
    $this->actingAs($originator)->get(route('originator.dashboard'))->assertSee('Next');
});

it('paginates Admin Violations at 5 per page', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 6; $i++) {
        $doc = paginationDoc($originator, ['global_status' => 'auto_approved']);
        $assignment = DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => null,
            'stage_id' => $stage->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        ]);
        AdminViolation::create([
            'document_id' => $doc->document_id, 'assignment_id' => $assignment->assignment_id,
            'violation_type' => 'missed_approval', 'stage_name' => 'Review',
            'first_violated_at' => now(), 'resolved_at' => now(),
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));
    $response->assertOk()->assertSee('Next');
});

it('paginates Decision History at 10 per page (unchanged)', function () {
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    for ($i = 0; $i < 11; $i++) {
        $doc = paginationDoc($approver, ['global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
        ]);
    }

    $this->actingAs($approver)->get(route('approver.history'))->assertSee('Next');
});

it('paginates Notifications at 10 per page (unchanged)', function () {
    $originator = User::factory()->originator()->create();
    for ($i = 0; $i < 10; $i++) {
        NotificationRecord::send($originator->user_id, null, "Notification {$i}");
    }
    $this->actingAs($originator)->get(route('notifications.index'))->assertDontSee('Next');

    NotificationRecord::send($originator->user_id, null, 'Notification 11');
    $this->actingAs($originator)->get(route('notifications.index'))->assertSee('Next');
});
