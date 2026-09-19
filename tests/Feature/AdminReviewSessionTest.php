<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for extending review-session tracking (presence icon
 * / duration / movement-timeline entries) to an ADMIN — countsAsActive
 * Reviewer() applies broadly to any unresolved document an admin/approver
 * views, not merely browsing an already-resolved one (Archive, Audit
 * Logs). See DocumentReviewSession::countsAsActiveReviewer().
 */
function adminSessionDoc(array $overrides = []): DocumentRepository
{
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => 'admin-session-test-' . uniqid() . '.txt',
        'file_path' => 'documents/' . uniqid() . '.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'processing',
        'ml_category' => 'Job Order',
    ], $overrides));

    Storage::disk('local')->put($document->file_path, 'test content');

    return $document;
}

it('opens a review session for an admin viewing an unresolved document', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = adminSessionDoc();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    $this->actingAs($admin)->get(route('documents.file', $document))->assertOk();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $admin->user_id)->exists())->toBeTrue();
});

it('does not open a review session for an admin merely browsing a document with nothing pending', function () {
    $admin = User::factory()->admin()->create();
    $document = adminSessionDoc(['global_status' => 'approved']);

    $this->actingAs($admin)->get(route('documents.file', $document))->assertOk();

    expect(DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $admin->user_id)->exists())->toBeFalse();
});

it('closes the admin\'s session once they decide a seat directly via SLA Override', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = adminSessionDoc();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    $this->actingAs($admin)->get(route('documents.file', $document));
    $this->travel(15)->seconds();

    $this->actingAs($admin)->post(route('admin.sla.override', $assignment), [
        'decision' => 'approved', 'comments' => 'Approved on the approver\'s behalf.',
    ]);

    $session = DocumentReviewSession::where('document_id', $document->document_id)->where('user_id', $admin->user_id)->first();
    expect($session->closed_at)->not->toBeNull();
    expect($session->duration_seconds)->toBeGreaterThanOrEqual(5);
});
