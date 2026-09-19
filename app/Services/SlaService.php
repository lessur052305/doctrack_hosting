<?php

namespace App\Services;

use App\Events\DocumentStatusChanged;
use App\Models\AdminViolation;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\NotificationRecord;
use App\Models\SlaOutageWindow;
use App\Models\SlaViolation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SlaService
 * -----------
 * A missed deadline always resolves the same way now, regardless of WHO
 * was responsible for it — auto-approve immediately, log the violation
 * against whoever actually held the seat, let Admin review it afterward
 * rather than before:
 *
 *   - a real approver missed their own window -> escalateApproverMiss()
 *     -> SlaViolation logged against them.
 *   - a stage had no eligible approver, so Admin was the fallback, and
 *     Admin's own window (shown on the Unassigned Documents page) also
 *     passed -> escalateNeedsApprover() -> AdminViolation (missed_approval)
 *     logged against the Admin role.
 *
 * Either way, the resulting auto-approval still owes Admin a review
 * within ADMIN_REVIEW_WINDOW_HOURS — trackLateReviews() below is what
 * follows up on THAT, independent of which path produced the
 * auto-approval in the first place.
 *
 * Intended to run every few minutes via the scheduler (see bootstrap/app.php).
 */
class SlaService
{
    /** Below this many minutes, a gap in the heartbeat is treated as ordinary scheduler jitter, not a real outage. */
    private const OUTAGE_DETECTION_FLOOR_MINUTES = 5;

    private const HEARTBEAT_CACHE_KEY = 'sla_heartbeat_last_seen';

    public function __construct(private WorkflowService $workflow, private BusinessHoursService $businessHours)
    {
    }

    /**
     * Called from sla:check, which already runs every 5 minutes — stamps
     * "the scheduler ran, as of now" every time, and compares against the
     * PREVIOUS stamp. If that previous stamp is more than
     * OUTAGE_DETECTION_FLOOR_MINUTES old, the system (or the scheduler
     * driving it) wasn't running for that whole gap — inferred proof of
     * an outage, not a guess. Cache, not a DB column, since this is pure
     * liveness bookkeeping with no need to survive a cache flush; a lost
     * heartbeat just means the next tick establishes a fresh baseline
     * instead of (wrongly) reporting years of "downtime".
     */
    public function detectOutage(): ?SlaOutageWindow
    {
        $now = now();
        $lastSeen = Cache::get(self::HEARTBEAT_CACHE_KEY);
        Cache::forever(self::HEARTBEAT_CACHE_KEY, $now->toIso8601String());

        if (!$lastSeen) {
            return null; // first tick ever (or cache was cleared) — nothing to compare against yet
        }

        $lastSeen = \Carbon\Carbon::parse($lastSeen);
        if ($lastSeen->diffInMinutes($now) < self::OUTAGE_DETECTION_FLOOR_MINUTES) {
            return null;
        }

        $minutesLost = $this->businessHours->businessMinutesLostToOutage($lastSeen, $now);
        if ($minutesLost <= 0) {
            return null; // the whole gap fell outside working hours — nobody actually lost review time
        }

        return SlaOutageWindow::create([
            'started_at' => $lastSeen,
            'ended_at' => $now,
            'business_minutes_lost' => $minutesLost,
        ]);
    }

    /** Fairness floor (see compensateForOutage()) — never less than this much usable time after recovery, no matter how small the strict calculation comes out to. */
    private const OUTAGE_COMPENSATION_FLOOR_MINUTES = 30;

    /**
     * The detect+compensate pair, as one step — called from two places:
     * the periodic sweep() below (a backstop), and CheckForSlaOutage
     * middleware (the real, fast path — see that class' docblock for why
     * "the first thing that runs after recovery" beats waiting on any
     * schedule). Both call sites get identical behavior for free, since
     * this is the one place the pairing is defined.
     *
     * @return array{outage: ?SlaOutageWindow, compensated: int}
     */
    public function checkForOutageRecovery(): array
    {
        $outage = $this->detectOutage();
        $compensated = $outage ? $this->compensateForOutage($outage) : 0;

        return ['outage' => $outage, 'compensated' => $compensated];
    }

    public function sweep(): array
    {
        $outageCheck = $this->checkForOutageRecovery();

        return [
            'outage_detected' => $outageCheck['outage'] !== null,
            'deadlines_compensated' => $outageCheck['compensated'],
            'late_review_reminders_sent' => $this->trackLateReviews(),
            'urgent_approver_reminders_sent' => $this->remindStillUrgentApprovers(),
        ];
    }

    /**
     * Only touches assignments that were actually AT RISK when the outage
     * hit — Urgent, Normal, or already Expired (urgencyRank() 1, 2, or 4)
     * — a document with days of slack left (rank 3, "Low") was never
     * going to be affected by a short outage, so leaving it alone avoids
     * both pointless deadline churn and a notification nobody needed.
     * Expired is deliberately included, not just Urgent/Normal: this
     * sweep runs AFTER the outage has already ended, so an assignment
     * that was Urgent right as the outage hit will usually have already
     * ticked over to "Expired" (0 seconds remaining) by the time this
     * runs — excluding Expired would miss exactly the case this feature
     * exists for, and let it fall through to an uncompensated auto-
     * approval instead.
     *
     * The new deadline is whichever is LATER: the strict business-hours-
     * aware calculation (push the current deadline forward by exactly how
     * many working minutes the outage cost, via addBusinessMinutes — see
     * its docblock for why this isn't a naive "add the raw outage
     * length"), or a flat OUTAGE_COMPENSATION_FLOOR_MINUTES after the
     * outage actually ended. Without the floor, a short overlap right at
     * the edge of closing time could compensate someone with a window so
     * thin (a few minutes at the start of the next working day) that it's
     * not a fair chance to act, even though it's technically accurate.
     * Either way, never pushed past the assignment's own due date — an
     * outage extends the approver's working budget, not what the
     * Originator was promised.
     */
    private function compensateForOutage(SlaOutageWindow $outage): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', false)
            ->whereNotNull('sla_expires_at')
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count, $outage) {
                if (!in_array($assignment->urgencyRank(), [1, 2, 4], true)) {
                    return;
                }

                $strict = $this->businessHours->addBusinessMinutes($assignment->sla_expires_at, $outage->business_minutes_lost);
                $floor = $this->businessHours->addBusinessMinutes($outage->ended_at, self::OUTAGE_COMPENSATION_FLOOR_MINUTES);
                $newExpiry = $strict->greaterThan($floor) ? $strict : $floor;

                if ($assignment->due_date && $newExpiry->greaterThan($assignment->due_date)) {
                    $newExpiry = $assignment->due_date->copy();
                }

                if ($newExpiry->lessThanOrEqualTo($assignment->sla_expires_at)) {
                    return; // due-date clamp already left nothing to compensate
                }

                $assignment->sla_expires_at = $newExpiry;
                $assignment->save();

                NotificationRecord::send($assignment->user_id, $assignment->document_id,
                    "Your review deadline for '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') was extended " .
                    'to account for a recent system outage — you were not penalized for time the system itself was unreachable.');

                $count++;
            });

        if ($count > 0) {
            AuditLog::record(null, null, 'sla_outage_compensation',
                "Detected a system outage from {$outage->started_at->toDayDateTimeString()} to {$outage->ended_at->toDayDateTimeString()} " .
                "({$outage->business_minutes_lost} working minute(s) lost) — {$count} approver deadline(s) extended to compensate.");
        }

        $outage->compensated_at = now();
        $outage->save();

        return $count;
    }

    /**
     * The "born urgent" notification (WorkflowService::assignStage()) fires
     * exactly once, the instant an assignment is created with an already-
     * short window. If the approver isn't looking right then, nothing
     * nudges them again before it actually auto-approves — this sends ONE
     * follow-up, right as the same Urgent window is about to run out,
     * guarded by urgent_reminder_sent_at.
     */
    private function remindStillUrgentApprovers(): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', false)
            ->whereNull('urgent_reminder_sent_at')
            ->whereNotNull('sla_expires_at')
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                if ($assignment->urgencyRank() !== 1) {
                    return;
                }

                $assignment->urgent_reminder_sent_at = now();
                $assignment->save();

                NotificationRecord::send($assignment->user_id, $assignment->document_id,
                    "FINAL CALL: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') is about to breach its SLA window " .
                    '— please act now before it auto-approves.',
                    'high');

                $count++;
            });

        return $count;
    }

    /** How often a follow-up fires while an auto-approval sits unreviewed past its window, and how many times total before it stops nudging (the violation itself keeps accruing silently after that — see AdminViolation::hoursOverdue()). */
    private const LATE_REVIEW_NOTIFICATION_INTERVAL_HOURS = 1;
    private const LATE_REVIEW_NOTIFICATION_CAP = 24;

    /**
     * Closes the gap between "the system auto-approved this" and "an admin
     * actually looked at it" — autoApproveOne() already sends an immediate
     * in-app notification the moment auto-approval happens, but nothing
     * previously followed up if that sat unreviewed. Triggered off each
     * assignment's own review_due_at (set at auto-approval time), NOT the
     * document's due date: a document can have days of runway left while
     * still needing a prompt review.
     *
     * ONE AdminViolation row per incident (not one per hour) — created the
     * moment the review window first lapses, updated in place as
     * notifications go out, resolved once AdminController::
     * reviewAutoApproval() actually happens. Notifications repeat hourly,
     * capped at LATE_REVIEW_NOTIFICATION_CAP total, so an admin away for a
     * week doesn't come back to hundreds of identical pings — the
     * violation itself keeps accruing (see hoursOverdue()) even after the
     * nudging stops.
     */
    private function trackLateReviews(): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->whereNotNull('review_due_at')
            ->where('review_due_at', '<=', now())
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                $violation = AdminViolation::firstOrCreate(
                    ['assignment_id' => $assignment->assignment_id, 'violation_type' => 'late_review', 'resolved_at' => null],
                    [
                        'document_id' => $assignment->document_id,
                        'stage_name' => $assignment->stage->stage_name,
                        'first_violated_at' => $assignment->review_due_at,
                        'notification_count' => 0,
                    ]
                );

                if ($violation->notification_count >= self::LATE_REVIEW_NOTIFICATION_CAP) {
                    return;
                }
                // abs(): Carbon 3's diffInHours() returns a signed float
                // depending on direction, not always positive even though
                // $now is always later here — same gotcha as
                // escalateApproverMiss()'s duration_overdue calculation.
                if ($violation->last_notified_at && abs(now()->diffInHours($violation->last_notified_at)) < self::LATE_REVIEW_NOTIFICATION_INTERVAL_HOURS) {
                    return;
                }

                $violation->last_notified_at = now();
                $violation->notification_count++;
                $violation->save();

                foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
                    NotificationRecord::send($admin->user_id, $assignment->document_id,
                        "URGENT: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') was auto-approved " .
                        "and still hasn't been reviewed — it's now {$violation->hoursOverdue()}h past its review window. Please confirm or dispute it now.",
                        'high');
                }

                $count++;
            });

        return $count;
    }

    // trackLateMlReviews() (the classification-review counterpart to
    // trackLateReviews() above, keyed on the now-retired ml_review_status/
    // ml_review_due_at) was removed along with the manual classification
    // and readability review queues — see WorkflowService::ingest()'s
    // $belowChanceFloor docblock. Both are fully automatic now: nothing
    // sets ml_review_status or readability_review_status to 'pending'
    // anymore, so there was never anything left for this sweep to find.

    /**
     * A real approver missed their own SLA window — auto-approves
     * immediately, Admin reviews after. A stage with no eligible approver
     * no longer reaches this at all: it's auto-approved right at routing
     * time instead (see WorkflowService::assignStage()/
     * autoApproveNoEligibleApprover()), so there's nothing left here to
     * dispatch between two cases.
     */
    public function escalate(DocumentAssignment $assignment): void
    {
        $this->escalateApproverMiss($assignment);
    }

    /**
     * A real approver had their own fair window and missed it. Auto-
     * approves right away (see autoApproveOne(), which sets a
     * review_due_at so Admin still reviews it afterward, just not
     * before). The SLA violation itself is still logged — the approver
     * genuinely did miss their window, and that stays a real,
     * accountable fact regardless of what happens next.
     */
    private function escalateApproverMiss(DocumentAssignment $assignment): void
    {
        // abs()+round(): Carbon 3's diffInMinutes() returns a signed float
        // even with the default $absolute param, so the sign and
        // fractional part both need normalizing before this hits an
        // unsignedInteger column.
        SlaViolation::create([
            'document_id' => $assignment->document_id,
            'assignment_id' => $assignment->assignment_id,
            'approver_id' => $assignment->user_id,
            'violation_timestamp' => now(),
            'duration_overdue' => (int) round(abs(now()->diffInMinutes($assignment->sla_expires_at))),
            'stage_name' => $assignment->stage->stage_name,
        ]);

        $this->autoApproveOne($assignment);
    }

    /**
     * A stage had no eligible approver — auto-approved immediately (see
     * WorkflowService::assignStage()/autoApproveDeactivatedSeat(), which
     * call this instead of parking the seat in a queue), same mechanism
     * as an approver's own missed deadline. Still logs an AdminViolation
     * against the Admin role rather than a real approver (there isn't
     * one), so this still shows up in the SLA Violations report — just
     * always created already resolved, since the auto-approval IS the
     * resolution, happening in the very same instant rather than after a
     * separate fallback window later passed (contrast
     * trackLateReviews()'s late_review violations, which stay open until
     * actually reviewed). Public: called directly from WorkflowService,
     * not reached through escalate() anymore — a stage with no eligible
     * approver never sits pending long enough to have a deadline to miss.
     */
    public function autoApproveNoEligibleApprover(DocumentAssignment $assignment): void
    {
        $now = now();

        AdminViolation::create([
            'document_id' => $assignment->document_id,
            'assignment_id' => $assignment->assignment_id,
            'violation_type' => 'missed_approval',
            'stage_name' => $assignment->stage->stage_name,
            'first_violated_at' => $now,
            'resolved_at' => $now,
        ]);

        $this->autoApproveOne($assignment);
    }

    /** How long Admin has to review an auto-approved stage before a late review gets logged (see reviewDueAt() below) — matches the old admin_grace_hours default, not a separately invented number. */
    private const ADMIN_REVIEW_WINDOW_HOURS = 6;

    /**
     * Shared by both auto-approval paths above — same outcome either way,
     * just triggered by a different missed deadline.
     */
    public function autoApproveOne(DocumentAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $document = $assignment->document;

            AuditLog::record(null, $document->document_id, 'auto_approve',
                "System auto-approved stage '{$assignment->stage->stage_name}' — no human decision was made in time.");

            // Deliberately NOT phrased as final/done — the Originator
            // needs to understand this hasn't actually been reviewed by
            // a person yet, only auto-approved because nobody acted in
            // time. See status-badge.blade.php for the matching visual
            // treatment (not the same green as a real approval).
            NotificationRecord::send($document->originator_id, $document->document_id,
                "Your document '{$document->title}' (stage '{$assignment->stage->stage_name}') was auto-approved because nobody " .
                'acted on it in time — an Admin will still give it a final check, and you\'ll be notified if anything changes.', 'high');

            foreach (User::whereIn('role', ['admin', 'approver'])->where('is_active', true)->get() as $u) {
                NotificationRecord::send($u->user_id, $document->document_id,
                    "HIGH PRIORITY: '{$document->title}' had a stage auto-approved by the system without human sign-off. Please review.",
                    'high');
            }

            // individual_status and auto_approved must be set here —
            // completeStage() only finalizes the DOCUMENT's
            // global_status; it never touches the assignment's own
            // status (that's the caller's job, same as decide() already
            // does). Without this, the assignment stays 'pending'
            // forever and would get caught — and re-notified on — every
            // subsequent sweep.
            $assignment->individual_status = 'approved';
            $assignment->auto_approved = true;
            $assignment->acted_at = now();
            // Admin's own soft review deadline — a flat window capped so
            // it never runs past the document's due date, checked by
            // trackLateReviews() above and AdminController::
            // reviewAutoApproval() to decide whether a review was late —
            // crossing it doesn't block or auto-trigger anything by
            // itself.
            $flatReviewDeadline = now()->addHours(self::ADMIN_REVIEW_WINDOW_HOURS);
            $assignment->review_due_at = ($assignment->due_date && $flatReviewDeadline->greaterThan($assignment->due_date))
                ? $assignment->due_date->copy()
                : $flatReviewDeadline;
            $assignment->save();

            $this->workflow->completeStage($assignment, 'approved', true);
        });
    }
}
