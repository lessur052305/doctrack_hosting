<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    // 2026-08-12 is a Wednesday, comfortably within business hours — pins
    // "now" so business-hours-aware SLA math (addBusinessMinutes, etc.)
    // behaves the same no matter when this suite actually runs.
    $this->travelTo(\Carbon\Carbon::parse('2026-08-12 10:00:00'));
});

function classifiedJobOrderDueIn(User $originator, int $minutesUntilDue): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'urgent-test.txt',
        'file_path' => 'documents/urgent-test.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addMinutes($minutesUntilDue),
        'global_status' => 'classified_validated',
    ]);
}

function escalatableAssignment(int $minutesUntilDue = 60 * 24): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'grace-test.txt',
        'file_path' => 'documents/grace-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addMinutes($minutesUntilDue),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
    ]);
}

// --- Approver "born Urgent" notification (WorkflowService::assignStage()) ---

test('an approver gets a separate URGENT notification when their new assignment is born with a very short window', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    // Just over the 1-hour minimum due-date buffer -> the tiered SLA
    // formula gives a flat 15-minute window, which is always <= the
    // 30-minute Urgent threshold.
    $document = classifiedJobOrderDueIn($originator, 61);
    app(WorkflowService::class)->routeToWorkflow($document);

    expect(NotificationRecord::where('recipient_id', $approver->user_id)
        ->where('priority', 'high')
        ->where('message_body', 'like', '%URGENT%very short window to act%')
        ->exists())->toBeTrue();
});

test('an approver does NOT get the extra URGENT notification when their new assignment has a comfortable window', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $document = classifiedJobOrderDueIn($originator, 60 * 24 * 3); // 3 days out
    app(WorkflowService::class)->routeToWorkflow($document);

    expect(NotificationRecord::where('recipient_id', $approver->user_id)
        ->where('message_body', 'like', '%very short window to act%')
        ->exists())->toBeFalse();
});

// The "short grace window" notification (old SlaService::escalate()'s
// needs_approver branch) is gone along with the grace window itself — a
// needs_approver seat now auto-approves the instant ITS OWN deadline
// passes, same as a real approver's miss, so there's no second window
// left to warn about.

test('escalate() no longer sends any email — SLA escalation is in-app/notification only now', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $assignment = escalatableAssignment();

    app(SlaService::class)->escalate($assignment);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

// --- Late-review tracking (SlaService::trackLateReviews()) ---

/**
 * $reviewDueOffsetMinutes is relative to now — negative means
 * review_due_at has already passed (the 6-hour Admin review window
 * elapsed, see SlaService::autoApproveOne()), positive means it's still
 * ahead. Due date is deliberately always far in the future here — the
 * reminder is triggered by review_due_at now, not due-date proximity
 * (see remindUnreviewedAutoApprovals()'s docblock for why).
 */
function unreviewedAutoApproval(int $reviewDueOffsetMinutes): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'unreviewed-auto-approval.txt',
        'file_path' => 'documents/unreviewed-auto-approval.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDays(3),
        'global_status' => 'auto_approved',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'auto_approved' => true,
        'acted_at' => now()->subHour(),
        'sla_expires_at' => now()->subHours(13),
        'review_due_at' => now()->addMinutes($reviewDueOffsetMinutes),
    ]);
}

test('an unreviewed auto-approval already past its review window triggers one reminder to every admin and opens an Admin violation', function () {
    $admin = User::factory()->admin()->create();
    $assignment = unreviewedAutoApproval(-10); // review_due_at was 10 minutes ago

    $sent = app(SlaService::class)->sweep()['late_review_reminders_sent'];

    $violation = \App\Models\AdminViolation::where('assignment_id', $assignment->assignment_id)->where('violation_type', 'late_review')->first();

    expect($sent)->toBe(1)
        ->and($violation)->not->toBeNull()
        ->and($violation->resolved_at)->toBeNull() // still open — nobody's reviewed it yet
        ->and($violation->notification_count)->toBe(1)
        ->and(NotificationRecord::where('recipient_id', $admin->user_id)
            ->where('priority', 'high')
            ->where('message_body', 'like', '%auto-approved%still hasn\'t been reviewed%')
            ->exists())->toBeTrue();
});

test('an unreviewed auto-approval still within its review window does not trigger a reminder yet', function () {
    unreviewedAutoApproval(60 * 5); // review_due_at is still 5 hours away

    $sent = app(SlaService::class)->sweep()['late_review_reminders_sent'];

    expect($sent)->toBe(0);
});

test('the reminder does not repeat within the same hour — a second sweep right after does not re-notify', function () {
    User::factory()->admin()->create();
    $assignment = unreviewedAutoApproval(-10);

    $first = app(SlaService::class)->sweep()['late_review_reminders_sent'];
    $second = app(SlaService::class)->sweep()['late_review_reminders_sent'];

    expect($first)->toBe(1)->and($second)->toBe(0);
    // Still exactly one violation row — the second sweep updated it in
    // place rather than creating a duplicate.
    expect(\App\Models\AdminViolation::where('assignment_id', $assignment->assignment_id)->count())->toBe(1);
});

test('the reminder fires again once an hour has passed since the last one', function () {
    User::factory()->admin()->create();
    unreviewedAutoApproval(-10);

    app(SlaService::class)->sweep();
    $this->travel(61)->minutes();
    $sent = app(SlaService::class)->sweep()['late_review_reminders_sent'];

    expect($sent)->toBe(1);
});

test('a reviewed auto-approval never triggers the reminder, regardless of its review window', function () {
    $assignment = unreviewedAutoApproval(-10);
    $assignment->update(['admin_reviewed_at' => now(), 'admin_review_outcome' => 'confirmed']);

    $sent = app(SlaService::class)->sweep()['late_review_reminders_sent'];

    expect($sent)->toBe(0);
});
