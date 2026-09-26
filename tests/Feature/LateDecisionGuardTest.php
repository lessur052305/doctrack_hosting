<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * A seat whose SLA deadline has passed is auto-approved by the system (with an
 * SLA Violation against the approver). If that approver then tries to decide
 * it — through the web queue, a batch action, or the API — the attempt must be
 * refused. The on-demand check exists so a stale scheduler interval can never
 * let a late decision through; it previously guarded on a flag that nothing
 * sets anymore, so the late decision was recorded on top of the auto-approval.
 */
beforeEach(function () {
    $this->stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $this->approver = User::factory()->approver('Job Order')->create();
    $this->originator = User::factory()->originator()->create();
});

function overdueSeatFor(User $approver, WorkflowStage $stage, User $originator, string $title = 'late.txt'): DocumentAssignment
{
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => $title, 'file_path' => "documents/{$title}",
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDays(2), 'global_status' => 'classified_validated',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(20), // deadline already passed, the sweep hasn't run yet
    ]);
}

function withReviewTime(User $approver, DocumentAssignment ...$seats): void
{
    foreach ($seats as $seat) {
        DocumentReviewSession::create([
            'document_id' => $seat->document_id, 'user_id' => $approver->user_id,
            'opened_at' => now()->subMinutes(5), 'closed_at' => now()->subMinutes(4), 'duration_seconds' => 60,
        ]);
    }
}

it('refuses a late web decision and leaves the auto-approval untouched', function () {
    $seat = overdueSeatFor($this->approver, $this->stage, $this->originator);
    withReviewTime($this->approver, $seat);

    $this->actingAs($this->approver)
        ->postJson(route('approver.assignments.decide', $seat), ['decision' => 'rejected', 'comments' => 'too late'])
        ->assertStatus(409);

    $seat = $seat->fresh();
    expect($seat->individual_status)->toBe('approved')
        ->and($seat->auto_approved)->toBeTrue()
        ->and($seat->comments)->toBeNull()
        ->and($seat->document->fresh()->global_status)->not->toBe('rejected')
        ->and(SlaViolation::where('assignment_id', $seat->assignment_id)->count())->toBe(1);
});

it('refuses a late API decision the same way', function () {
    $seat = overdueSeatFor($this->approver, $this->stage, $this->originator);

    $this->actingAs($this->approver, 'sanctum')
        ->postJson("/api/v1/assignments/{$seat->assignment_id}/decide", ['decision' => 'rejected', 'comments' => 'too late'])
        ->assertStatus(409);

    expect($seat->fresh()->individual_status)->toBe('approved')
        ->and($seat->fresh()->auto_approved)->toBeTrue()
        ->and($seat->document->fresh()->global_status)->not->toBe('rejected');
});

it('skips a late seat in a batch decision and still decides the on-time ones', function () {
    $late = overdueSeatFor($this->approver, $this->stage, $this->originator, 'late.txt');
    $onTime = overdueSeatFor($this->approver, $this->stage, $this->originator, 'ontime.txt');
    $onTime->update(['sla_expires_at' => now()->addHours(3)]);
    withReviewTime($this->approver, $late, $onTime);

    $response = $this->actingAs($this->approver)->postJson(route('approver.assignments.decideBatch'), [
        'assignment_ids' => [$late->assignment_id, $onTime->assignment_id],
        'decision' => 'rejected',
        'comments' => 'batch reject',
    ])->assertOk();

    expect($response->json('status'))->toContain('1 assignment(s)')->toContain('auto-approved')
        // the late seat kept the system's auto-approval; the batch rejection never touched it
        ->and($late->fresh()->individual_status)->toBe('approved')
        ->and($late->fresh()->auto_approved)->toBeTrue()
        ->and($late->document->fresh()->global_status)->not->toBe('rejected')
        // the on-time seat was decided normally
        ->and($onTime->fresh()->individual_status)->toBe('rejected')
        ->and($onTime->fresh()->auto_approved)->toBeFalse();
});
