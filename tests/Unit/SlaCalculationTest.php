<?php

use App\Models\DocumentAssignment;
use App\Models\SlaSetting;
use App\Services\WorkflowService;
use Carbon\Carbon;

// Mon–Sat 9:00–17:00 — matches this app's default configuration.
beforeEach(function () {
    SlaSetting::current()->update([
        'work_start_time' => '09:00:00',
        'work_end_time' => '17:00:00',
        'working_days' => [1, 2, 3, 4, 5, 6],
    ]);
});

/**
 * Builds a transient (unsaved) assignment with only the two fields the
 * tiered SLA formula actually reads: created_at (the anchor) and due_date.
 */
function assignmentWith(string $createdAt, string $dueDate): DocumentAssignment
{
    $assignment = new DocumentAssignment(['due_date' => $dueDate]);
    $assignment->created_at = Carbon::parse($createdAt);

    return $assignment;
}

test('tier 1: a due date 60 minutes or less away gets a flat 15-minute window', function () {
    $workflow = app(WorkflowService::class);

    // 16 minutes from creation to due date -> Tier 1 (flat 15 min).
    $assignment = assignmentWith('2026-07-16 14:48:17', '2026-07-16 15:04:17');

    $expiry = $workflow->recalculateAssignmentSlaExpiry($assignment);

    expect($expiry->toDateTimeString())->toBe('2026-07-16 15:03:17');
});

test('tier 2: a due date more than 60 minutes away gets 25% of the remaining time', function () {
    $workflow = app(WorkflowService::class);

    // 120 minutes from creation to due date -> Tier 2: 25% of 120 = 30 min.
    $assignment = assignmentWith('2026-07-16 09:00:00', '2026-07-16 11:00:00');

    $expiry = $workflow->recalculateAssignmentSlaExpiry($assignment);

    expect($expiry->toDateTimeString())->toBe('2026-07-16 09:30:00');
});

test('tier 2 is capped at 6 hours no matter how far away the due date is', function () {
    $workflow = app(WorkflowService::class);

    // Created just before business hours open, due date 2 days out — 25% of
    // that would be many hours, but the formula must cap it at 360 minutes.
    $assignment = assignmentWith('2026-07-16 00:54:36', '2026-07-18 12:12:00');

    $expiry = $workflow->recalculateAssignmentSlaExpiry($assignment);

    // 00:54 snaps to 9:00 AM open, +360 min (6h) fits entirely within the
    // same working day (9-5 = 8h available) -> 3:00 PM same day.
    expect($expiry->toDateTimeString())->toBe('2026-07-16 15:00:00');
});

test('the computed SLA deadline never exceeds the document due date', function () {
    $workflow = app(WorkflowService::class);

    // Created right before closing time with only 17 minutes until the due
    // date — the flat 15-minute Tier 1 window would land after business
    // hours roll over to the next day, which is later than the due date
    // itself, so the clamp must force it back down to the due date exactly.
    $assignment = assignmentWith('2026-07-16 16:47:00', '2026-07-16 17:04:00');

    $expiry = $workflow->recalculateAssignmentSlaExpiry($assignment);

    expect($expiry->toDateTimeString())->toBe('2026-07-16 17:04:00');
});

test('a holiday added after an assignment is created correctly shifts its recalculated deadline', function () {
    $workflow = app(WorkflowService::class);

    // Thursday 3:30 PM, 6h budget: 90 min used today, 270 min remaining
    // would normally roll to Friday — unless Friday becomes a holiday.
    $assignment = assignmentWith('2026-07-16 15:30:00', '2026-07-20 17:00:00');

    \App\Models\SlaHoliday::create(['holiday_date' => '2026-07-17']);

    $expiry = $workflow->recalculateAssignmentSlaExpiry($assignment);

    expect($expiry->toDateTimeString())->toBe('2026-07-18 13:30:00'); // Saturday, not Friday
});
