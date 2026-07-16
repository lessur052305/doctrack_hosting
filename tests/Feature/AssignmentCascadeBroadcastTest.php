<?php

use App\Events\AssignmentRouted;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Event;

/**
 * Regression coverage: when one approver rejects their stage,
 * WorkflowService::completeStage() auto-closes every OTHER pending
 * assignment on that document (see its "Auto-closed — document rejected
 * at another stage" comment). Those other approvers never touched
 * anything themselves, so without an explicit broadcast targeting THEIR
 * queue specifically, their dashboard has no way to find out — the
 * document would keep appearing in their queue as if still awaiting
 * their decision, forever (or until their next slow fallback poll).
 */
beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Budget Check', 'sequence_order' => 2]);
});

it('notifies a sibling approver\'s own channel when their assignment is auto-closed by a rejection elsewhere', function () {
    Event::fake([AssignmentRouted::class]);

    $originator = User::factory()->originator()->create();
    $approverOne = User::factory()->approver('Job Order')->create();
    $approverTwo = User::factory()->approver('Job Order')->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);
    $stageOne = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $stageTwo = WorkflowStage::where('stage_name', 'Budget Check')->first();

    $assignmentOne = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stageOne->stage_id, 'user_id' => $approverOne->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);
    $assignmentTwo = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stageTwo->stage_id, 'user_id' => $approverTwo->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);

    Event::fake([AssignmentRouted::class]); // clear the two "created" broadcasts above, isolate the decision itself

    app(WorkflowService::class)->decide($assignmentOne, $approverOne, 'rejected', 'not acceptable');

    // Approver Two's own assignment was auto-closed as a side effect —
    // never actioned by Approver Two themselves — so their channel must
    // still have gotten an AssignmentRouted push telling them to refresh.
    Event::assertDispatched(AssignmentRouted::class, fn ($e) => $e->assignment->assignment_id === $assignmentTwo->assignment_id);

    expect($assignmentTwo->fresh()->individual_status)->toBe('rejected');
    expect($assignmentTwo->fresh()->comments)->toContain('Auto-closed');
});

it('notifies a sibling approver holding a DIFFERENT, unaffected stage when this stage is decided', function () {
    // Distinct from the rejection-cascade test above: here approverTwo's
    // OWN assignment never changes status at all (approverOne approves,
    // so nothing auto-closes) — but the approver dashboard renders the
    // full stage pipeline for context (see
    // approver/partials/queue.blade.php's <x-workflow-stage-list>), so
    // approverTwo still needs a push telling them to re-fetch and see
    // stage one now shows "approved" next to their own pending stage two.
    Event::fake([AssignmentRouted::class]);

    $originator = User::factory()->originator()->create();
    $approverOne = User::factory()->approver('Job Order')->create();
    $approverTwo = User::factory()->approver('Job Order')->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);
    $stageOne = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $stageTwo = WorkflowStage::where('stage_name', 'Budget Check')->first();

    $assignmentOne = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stageOne->stage_id, 'user_id' => $approverOne->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);
    $assignmentTwo = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stageTwo->stage_id, 'user_id' => $approverTwo->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);

    Event::fake([AssignmentRouted::class]); // clear the two "created" broadcasts above, isolate the decision itself

    app(WorkflowService::class)->decide($assignmentOne, $approverOne, 'approved', null);

    // approverTwo's own assignment is untouched...
    expect($assignmentTwo->fresh()->individual_status)->toBe('pending');

    // ...but they must still have received a push targeted at THEIR OWN
    // channel, carrying the OTHER approver's assignment, since that's what
    // changed on the document they both hold a stake in.
    Event::assertDispatched(
        AssignmentRouted::class,
        fn ($e) => $e->targetApproverId === $approverTwo->user_id
            && $e->assignment->assignment_id === $assignmentOne->assignment_id
    );
});

it('drops the auto-closed assignment out of the sibling approver\'s pending queue', function () {
    $originator = User::factory()->originator()->create();
    $approverOne = User::factory()->approver('Job Order')->create();
    $approverTwo = User::factory()->approver('Job Order')->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);
    $stageOne = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $stageTwo = WorkflowStage::where('stage_name', 'Budget Check')->first();

    $assignmentOne = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stageOne->stage_id, 'user_id' => $approverOne->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stageTwo->stage_id, 'user_id' => $approverTwo->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'escalated_to_admin' => false, 'auto_approved' => false,
    ]);

    $this->actingAs($approverTwo)->getJson(route('approver.assignments.poll'))
        ->assertJson(['pending_count' => 1]);

    app(WorkflowService::class)->decide($assignmentOne, $approverOne, 'rejected', null);

    $this->actingAs($approverTwo)->getJson(route('approver.assignments.poll'))
        ->assertJson(['pending_count' => 0]);
});
