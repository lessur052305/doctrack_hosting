<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;

function pendingAssignment(array $overrides = []): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'test.txt',
        'file_path' => 'documents/test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create(array_merge([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
    ], $overrides));
}

test('a real approver missing their own SLA window is auto-approved immediately', function () {
    // Admin still reviews it afterward instead (review_due_at), not
    // before. The SLA violation itself is still logged either way.
    $assignment = pendingAssignment();

    app(SlaService::class)->escalate($assignment);

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved')
        ->and($fresh->auto_approved)->toBeTrue()
        ->and($fresh->review_due_at)->not->toBeNull()
        ->and(SlaViolation::where('assignment_id', $assignment->assignment_id)->exists())->toBeTrue()
        ->and(AdminViolation::where('assignment_id', $assignment->assignment_id)->exists())->toBeFalse()
        ->and(NotificationRecord::where('recipient_id', $assignment->document->originator_id)
            ->where('message_body', 'like', '%will still give it a final check%')->exists())->toBeTrue();
});

// A seat with no eligible approver no longer reaches escalate() at all —
// it auto-approves immediately at routing time instead (see
// WorkflowService::assignStage()/autoApproveDeactivatedSeat(), which
// call SlaService::autoApproveNoEligibleApprover() directly). Coverage
// for that path now lives in NoEligibleApproverAutoApprovalTest.php; the
// two tests below cover autoApproveNoEligibleApprover() itself, called
// the same way those call sites do.

test('a seat with no eligible approver is auto-approved immediately, logging an Admin violation instead of an approver one', function () {
    $assignment = pendingAssignment(['user_id' => null]);

    app(SlaService::class)->autoApproveNoEligibleApprover($assignment);

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved')
        ->and($fresh->auto_approved)->toBeTrue()
        ->and($fresh->review_due_at)->not->toBeNull()
        ->and(SlaViolation::where('assignment_id', $assignment->assignment_id)->exists())->toBeFalse() // no approver to blame
        ->and(AdminViolation::where('assignment_id', $assignment->assignment_id)
            ->where('violation_type', 'missed_approval')->exists())->toBeTrue();
});

test('the missed_approval Admin violation is logged already resolved — nothing left to wait on', function () {
    $assignment = pendingAssignment(['user_id' => null]);

    app(SlaService::class)->autoApproveNoEligibleApprover($assignment);

    $violation = AdminViolation::where('assignment_id', $assignment->assignment_id)->where('violation_type', 'missed_approval')->first();

    expect($violation)->not->toBeNull()
        ->and($violation->resolved_at)->not->toBeNull()
        ->and($violation->first_violated_at->equalTo($violation->resolved_at))->toBeTrue()
        ->and($violation->stage_name)->toBe('Technical Review');
});
