<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;

/**
 * Replaces the old Unassigned Documents module (a manual admin queue +
 * an SLA-deadline-then-escalate wait) with instant auto-approval — see
 * WorkflowService::assignStage()/autoApproveNoEligibleApprover()'s own
 * docblocks for the full reasoning.
 */
function noApproverJobOrder(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'job_order.txt',
        'file_path' => 'documents/job_order.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addDays(3),
        'global_status' => 'classified_validated',
    ]);
}

test('a stage with no eligible approver is auto-approved immediately, not parked pending', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    // Deliberately no approver created for this category.

    $document = noApproverJobOrder($originator);
    app(WorkflowService::class)->routeToWorkflow($document);

    $assignment = DocumentAssignment::where('document_id', $document->document_id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->individual_status)->toBe('approved')
        ->and($assignment->auto_approved)->toBeTrue()
        ->and($assignment->user_id)->toBeNull()
        ->and($assignment->acted_at)->not->toBeNull()
        // Still reviewed after the fact — the whole point of reusing
        // autoApproveOne() rather than inventing a separate path.
        ->and($assignment->review_due_at)->not->toBeNull()
        ->and($assignment->admin_reviewed_at)->toBeNull();

    expect($document->fresh()->global_status)->toBe('auto_approved');

    expect(AdminViolation::where('assignment_id', $assignment->assignment_id)
        ->where('violation_type', 'missed_approval')->exists())->toBeTrue();
});

test('a stage with no eligible approver does not block a sibling stage that has one', function () {
    // Sequence matters here: the no-approver stage is FIRST, so the
    // routing loop resolves it before the second stage (with a real
    // approver) even gets its own assignment row created — exactly the
    // ordering hazard assignStage()'s docblock guards against.
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'No Approver Stage', 'sequence_order' => 1]);
    $realStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Real Approver Stage', 'sequence_order' => 2]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    // Restricted to ONLY the real stage — otherwise an unrestricted
    // approver is eligible for every stage in the category by default,
    // and "No Approver Stage" would wrongly get a real approver too.
    $approver->workflowStages()->sync([$realStage->stage_id]);

    $document = noApproverJobOrder($originator);
    app(WorkflowService::class)->routeToWorkflow($document);
    $document->refresh();

    $noApproverSeat = DocumentAssignment::where('document_id', $document->document_id)
        ->whereNull('user_id')->first();
    $realSeat = DocumentAssignment::where('document_id', $document->document_id)
        ->where('user_id', $approver->user_id)->first();

    expect($noApproverSeat->individual_status)->toBe('approved')
        ->and($noApproverSeat->auto_approved)->toBeTrue()
        ->and($realSeat)->not->toBeNull()
        ->and($realSeat->individual_status)->toBe('pending')
        // The critical assertion: the document must NOT have been
        // finalized just because the first (no-approver) stage resolved
        // before the second stage's own assignment row even existed yet.
        ->and($document->global_status)->toBe('classified_validated');

    // And the real approver can still decide their own stage normally afterwards.
    app(WorkflowService::class)->decide($realSeat, $approver, 'approved');

    expect($document->fresh()->global_status)->toBe('approved');
});

test('every stage lacking an eligible approver still resolves correctly, even when every stage on the document has none', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage A', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage B', 'sequence_order' => 2]);
    $originator = User::factory()->originator()->create();

    $document = noApproverJobOrder($originator);
    app(WorkflowService::class)->routeToWorkflow($document);

    $assignments = DocumentAssignment::where('document_id', $document->document_id)->get();

    expect($assignments)->toHaveCount(2)
        ->and($assignments->every(fn ($a) => $a->individual_status === 'approved' && $a->auto_approved))->toBeTrue();

    expect($document->fresh()->global_status)->toBe('auto_approved');
});

test('deactivating the last eligible approver on a seat auto-approves it immediately instead of flagging needs_approver', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $document = noApproverJobOrder($originator);
    app(WorkflowService::class)->routeToWorkflow($document);
    $assignment = DocumentAssignment::where('document_id', $document->document_id)->first();
    expect($assignment->individual_status)->toBe('pending');

    app(WorkflowService::class)->autoApproveDeactivatedSeat($assignment, $approver, 'left the company');

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved')
        ->and($fresh->auto_approved)->toBeTrue()
        ->and($fresh->reassigned_from)->toBe($approver->user_id)
        ->and($fresh->reassignment_reason)->toBe('left the company');

    expect(AdminViolation::where('assignment_id', $assignment->assignment_id)
        ->where('violation_type', 'missed_approval')->exists())->toBeTrue();
});

test('the old Unassigned Documents routes no longer exist', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => route('admin.unassigned.index'))->toThrow(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);
});
