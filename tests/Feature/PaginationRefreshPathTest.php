<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Regression coverage for a real reported bug: every paginated list's
 * Next/Previous links were built from $request->url() (or the implicit
 * default path resolver, which does the same thing) — correct when
 * rendered from the real page route, but WRONG when the same query-
 * building code runs from within that page's separate .../refresh route
 * (hit by the live-poll JS to swap the list in place). A live swap would
 * silently bake the bare-fragment URL into Next/Previous, so clicking one
 * navigated straight to an unstyled, layout-less fragment response
 * instead of the real page. Each test hits the REFRESH endpoint directly
 * (not the main page) and asserts the embedded links point at the real
 * page route, not the refresh route itself.
 */
function refreshPathDoc(User $originator, array $overrides = []): DocumentRepository
{
    return DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => 'refresh-path-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'upload_date' => now(),
        'global_status' => 'approved',
        'ml_category' => 'Job Order',
    ], $overrides));
}

it('Archive refresh fragment points Next/Previous at the real archive page, not the refresh route', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 6; $i++) {
        refreshPathDoc($admin);
    }

    $response = $this->actingAs($admin)->get(route('archive.refresh', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee(route('admin.archive'), false);
    $response->assertDontSee('archive/refresh?', false);
});

it('Admin Users refresh fragment points Next/Previous at the real users page, not the refresh route', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(5)->originator()->create();

    $response = $this->actingAs($admin)->get(route('admin.users.refresh'));

    $response->assertOk();
    $response->assertSee(route('admin.users'), false);
    $response->assertDontSee('users/refresh?', false);
});

it('Readability Review Queue refresh fragment points Next/Previous at the real ML training page, not the refresh route', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 6; $i++) {
        refreshPathDoc($admin, ['global_status' => 'processing', 'readability_review_status' => 'pending', 'readability_score' => 50]);
    }

    $response = $this->actingAs($admin)->get(route('admin.ml.review.refresh'));

    $response->assertOk();
    $response->assertSee(route('admin.ml.training'), false);
    $response->assertDontSee('ml-training/review/refresh?', false);
});

it('Approver Queue refresh fragment points Next/Previous at the real dashboard, not the refresh route', function () {
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    for ($i = 0; $i < 11; $i++) {
        $doc = refreshPathDoc($approver, ['global_status' => 'processing']);
        DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
        ]);
    }

    $response = $this->actingAs($approver)->get(route('approver.assignments.refresh'));

    $response->assertOk();
    $response->assertSee(route('approver.dashboard'), false);
    $response->assertDontSee('assignments/refresh?', false);
});

it('Decision History refresh fragment points Next/Previous at the real history page, not the refresh route', function () {
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    for ($i = 0; $i < 11; $i++) {
        $doc = refreshPathDoc($approver, ['global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
        ]);
    }

    $response = $this->actingAs($approver)->get(route('approver.history.refresh'));

    $response->assertOk();
    $response->assertSee(route('approver.history'), false);
    $response->assertDontSee('history/refresh?', false);
});

it('Notifications refresh (bell) does not affect the paginated index page path', function () {
    $originator = User::factory()->originator()->create();
    for ($i = 0; $i < 11; $i++) {
        NotificationRecord::send($originator->user_id, null, "Notification {$i}");
    }

    $response = $this->actingAs($originator)->get(route('notifications.index'));

    $response->assertOk();
    $response->assertSee(route('notifications.index'), false);
});

it('Originator dashboard refresh fragment points Next/Previous at the real dashboard, not the refresh route', function () {
    $originator = User::factory()->originator()->create();
    for ($i = 0; $i < 6; $i++) {
        refreshPathDoc($originator);
    }

    $response = $this->actingAs($originator)->get(route('originator.documents.refresh'));

    $response->assertOk();
    $response->assertSee(route('originator.dashboard'), false);
    $response->assertDontSee('documents/refresh?', false);
});
