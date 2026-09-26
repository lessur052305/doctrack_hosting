<?php

use App\Models\DocumentAssignment;
use App\Models\SlaHoliday;
use App\Services\WorkflowService;
use Carbon\Carbon;

// Mon–Sat 9:00–17:00 — the fixed config('sla.php') default (no longer
// admin-editable), so no setup is needed beyond that.

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

    // Created just before business hours open, due the following Wednesday —
    // about 43 WORKING hours away (Thu/Fri/Sat 8h each, Mon/Tue 8h each, Wed
    // 9-12:12). 25% of that would be ~10 hours, but the formula must cap it
    // at 360 minutes.
    $assignment = assignmentWith('2026-07-16 00:54:36', '2026-07-22 12:12:00');

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

    // Thursday 3:30 PM, due Monday 5 PM. With Friday a holiday, the working
    // time left is 1,050 min (Thu 90 + Sat 480 + Mon 480; Friday and Sunday
    // don't count), so the budget is 25% = 263 min: 90 used Thursday, the
    // remaining 173 would normally roll to Friday morning — unless Friday
    // becomes a holiday, which pushes it to Saturday 9:00 + 173 min.
    $assignment = assignmentWith('2026-07-16 15:30:00', '2026-07-20 17:00:00');

    SlaHoliday::create(['holiday_date' => '2026-07-17']);

    $expiry = $workflow->recalculateAssignmentSlaExpiry($assignment);

    expect($expiry->toDateTimeString())->toBe('2026-07-18 11:53:00'); // Saturday, not Friday
});

test('the remaining time is measured in WORKING minutes, so a night between now and the due date is not counted', function () {
    $workflow = app(WorkflowService::class);

    // Monday 4:00 PM, due Tuesday 9:30 AM: 17.5 wall-clock hours away, but
    // only 60 min (Mon) + 30 min (Tue) = 90 working minutes actually exist.
    // 25% of 90 = 22.5 -> 23 min, not the ~4 working hours a wall-clock diff
    // would have handed out (which ran all the way to the due date).
    $assignment = assignmentWith('2026-07-20 16:00:00', '2026-07-21 09:30:00');

    expect($workflow->recalculateAssignmentSlaExpiry($assignment)->toDateTimeString())->toBe('2026-07-20 16:23:00');
});

test('a second window opened when the first one ends still fits before the due date', function () {
    $workflow = app(WorkflowService::class);

    // Continuing the case above: the first group's window ends 4:23 PM Monday.
    // 37 min (Mon) + 30 min (Tue) = 67 working minutes remain -> 25% = 16.75
    // -> 17 min, ending 4:40 PM Monday — still comfortably inside the due date.
    $assignment = assignmentWith('2026-07-20 16:23:00', '2026-07-21 09:30:00');

    expect($workflow->recalculateAssignmentSlaExpiry($assignment)->toDateTimeString())->toBe('2026-07-20 16:40:00');
});
