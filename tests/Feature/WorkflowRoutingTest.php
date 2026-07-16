<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
});

/**
 * A pre-classified, pre-validated document — these tests exercise routing,
 * load balancing, and decision handling, not classification/validation
 * (see ValidationServiceTest and the classifier's own coverage for that),
 * so there's no need to run real text through the ML pipeline here.
 */
function classifiedJobOrder(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'job_order.txt',
        'file_path' => 'documents/job_order.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addMinutes(30),
        'global_status' => 'classified_validated',
    ]);
}

test('a validated document is routed to every configured stage for its category', function () {
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    expect(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(2);
});

test('a stage is routed to whichever eligible approver currently has the fewest pending assignments', function () {
    $originator = User::factory()->originator()->create();
    $busyApprover = User::factory()->approver('Job Order')->create();
    $freeApprover = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // Give the first approver existing pending assignments so they're no
    // longer the least-busy eligible candidate for the next document.
    $first = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($first);
    DocumentAssignment::where('document_id', $first->document_id)
        ->update(['user_id' => $busyApprover->user_id]);

    $second = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($second);

    $secondApprovers = DocumentAssignment::where('document_id', $second->document_id)->pluck('user_id')->unique();

    expect($secondApprovers->all())->toBe([$freeApprover->user_id]);
});

test('approving every stage finalizes the document as approved', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    $assignments = DocumentAssignment::where('document_id', $document->document_id)->get();
    foreach ($assignments as $assignment) {
        $workflow->decide($assignment, $approver, 'approved');
    }

    expect($document->fresh()->global_status)->toBe('approved');
});

test('rejecting one stage terminates the whole document and auto-closes every other pending stage', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = classifiedJobOrder($originator);
    $workflow->routeToWorkflow($document);

    $assignments = DocumentAssignment::where('document_id', $document->document_id)
        ->orderBy('assignment_id')
        ->get();

    // Reject only the first stage; the second was never individually decided.
    $workflow->decide($assignments->first(), $approver, 'rejected');

    $stillPendingCount = DocumentAssignment::where('document_id', $document->document_id)
        ->where('individual_status', 'pending')
        ->count();

    expect($document->fresh()->global_status)->toBe('rejected')
        ->and($stillPendingCount)->toBe(0)
        ->and($assignments->last()->fresh()->individual_status)->toBe('rejected')
        ->and($assignments->last()->fresh()->comments)->toContain('Auto-closed');
});
