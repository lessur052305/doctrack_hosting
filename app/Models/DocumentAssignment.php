<?php

namespace App\Models;

use App\Events\AssignmentRouted;
use App\Events\DocumentStatusChanged;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (document, stage, eligible approver) — a stage with 3
 * eligible approvers gets 3 rows, one per seat. A stage only completes
 * once every one of its rows is non-pending (see
 * WorkflowService::completeStage()); a single rejection on any row
 * immediately rejects the whole document. Each row keeps its own
 * independent SLA window, escalation, and admin-override — see
 * WorkflowService::assignStage().
 */
class DocumentAssignment extends Model
{
    protected $table = 'document_assignments';
    protected $primaryKey = 'assignment_id';

    protected static function booted(): void
    {
        // Broadcasts AssignmentRouted over Reverb the instant a new
        // assignment is routed — to every approver who has ANY assignment
        // on this document (including the new one's own holder), not just
        // the new one's own holder. The approver dashboard shows the full
        // stage pipeline for a document for context (see
        // approver/partials/queue.blade.php's <x-workflow-stage-list>), so
        // an approver already working stage 1 needs to know the moment
        // stage 2 gets routed to someone else too, not only when their own
        // stage changes.
        static::created(function (self $assignment) {
            static::notifyDocumentApprovers($assignment);
        });

        static::updated(function (self $assignment) {
            if (!$assignment->wasChanged('individual_status')) {
                return;
            }

            // A single stage being decided (e.g. Technical Review approved)
            // doesn't necessarily change the document's overall
            // global_status — later stages may still be pending — but the
            // originator's tracking page (Approval Stages list) needs to
            // update the moment it happens regardless. Reuses
            // DocumentStatusChanged rather than a new event class: same
            // channels/payload shape, and "something about my document
            // changed, go re-fetch it" is exactly the right signal either
            // way.
            event(new DocumentStatusChanged($assignment->document));

            // Same "notify everyone with a stake in this document" logic
            // as created() above — most importantly, this is what makes
            // WorkflowService::completeStage()'s rejection cascade (which
            // auto-closes every OTHER pending assignment on a document)
            // actually reach those other approvers' dashboards, since
            // none of them touched anything themselves and nothing else
            // in their own browser tab would otherwise trigger a refresh.
            static::notifyDocumentApprovers($assignment);
        });
    }

    /**
     * Fires one AssignmentRouted per distinct approver who currently has
     * (or ever had) an assignment on $assignment's document — see the
     * booted() hooks above for why this needs to be everyone, not just
     * $assignment's own holder.
     */
    private static function notifyDocumentApprovers(self $assignment): void
    {
        $approverIds = static::where('document_id', $assignment->document_id)->pluck('user_id')->unique();

        foreach ($approverIds as $approverId) {
            event(new AssignmentRouted($assignment, (int) $approverId));
        }
    }

    protected $fillable = [
        'document_id', 'user_id', 'stage_id', 'due_date', 'priority_rank',
        'individual_status', 'comments', 'sla_expires_at', 'admin_override_at',
        'admin_override_by', 'escalated_to_admin', 'escalated_at', 'escalation_reason', 'auto_approved', 'acted_at',
        'admin_reviewed_at', 'admin_reviewed_by', 'admin_review_note', 'admin_review_outcome',
        'review_reminder_sent_at', 'urgent_reminder_sent_at', 'grace_reminder_sent_at',
        'reassigned_at', 'reassigned_from', 'reassignment_reason',
        'cascade_closed_by', 'review_due_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'sla_expires_at' => 'datetime',
        'admin_override_at' => 'datetime',
        'acted_at' => 'datetime',
        'escalated_to_admin' => 'boolean',
        'escalated_at' => 'datetime',
        'auto_approved' => 'boolean',
        'admin_reviewed_at' => 'datetime',
        'review_reminder_sent_at' => 'datetime',
        'urgent_reminder_sent_at' => 'datetime',
        'grace_reminder_sent_at' => 'datetime',
        'reassigned_at' => 'datetime',
        'review_due_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    /**
     * Relationship method kept as `approver()` for readability throughout
     * the app (views, controllers) even though the underlying column is
     * named `user_id` per the required schema.
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'stage_id', 'stage_id');
    }

    public function adminOverrideBy()
    {
        return $this->belongsTo(User::class, 'admin_override_by', 'user_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'admin_reviewed_by', 'user_id');
    }

    public function reassignedFrom()
    {
        return $this->belongsTo(User::class, 'reassigned_from', 'user_id');
    }

    /** Who ACTUALLY made the rejection that cascade-closed this seat — see completeStage()'s rejection branch. Null unless this row was auto-closed by a sibling's reject. */
    public function cascadeClosedBy()
    {
        return $this->belongsTo(User::class, 'cascade_closed_by', 'user_id');
    }

    /**
     * Reject-vote status for THIS seat's stage (Feature: rejecting a
     * multi-approver stage now needs a strict majority — more than half —
     * instead of any single reject terminating the document; see
     * WorkflowService::completeStage()). A single-approver stage is
     * unaffected: threshold works out to 1, so that one decision is
     * already the whole vote, same as before this feature existed.
     *
     * rejectStillPossible goes false once enough OTHER seats on this
     * stage have already approved that no combination of the remaining
     * undecided seats could still reach the reject threshold — at that
     * point Reject is taken off the table for whoever's left (see
     * queue.blade.php) rather than offering a choice that can no longer
     * do anything.
     */
    public function stageRejectionStatus(): array
    {
        $seats = static::where('document_id', $this->document_id)
            ->where('stage_id', $this->stage_id)
            ->get();

        $total = $seats->count();
        $approved = $seats->whereIn('individual_status', ['approved', 'auto_approved'])->count();
        $rejected = $seats->where('individual_status', 'rejected')->count();
        $threshold = intdiv($total, 2) + 1;

        return [
            'total' => $total,
            'approved' => $approved,
            'rejected' => $rejected,
            'threshold' => $threshold,
            'majorityReached' => $rejected >= $threshold,
            'rejectStillPossible' => ($total - $approved) >= $threshold,
        ];
    }

    /** Seconds remaining before SLA violation — used for the countdown UI. */
    public function getSecondsRemainingAttribute(): ?int
    {
        if (!$this->sla_expires_at) {
            return null;
        }
        // Carbon 3's diffInSeconds() returns a float even with the signed
        // ($absolute=false) form; round explicitly rather than let PHP's
        // implicit float->int narrowing throw a deprecation warning.
        return (int) round(now()->diffInSeconds($this->sla_expires_at, false));
    }

    /**
     * How much actual WORKING time is left before this seat's SLA
     * deadline — unlike seconds_remaining above (a raw wall-clock diff,
     * which can look wildly inflated whenever it crosses an evening or
     * weekend), this only counts seconds inside real business hours. This
     * is the number the Urgent/Normal/Low/Expired badge and the "SLA
     * expires" countdown are both based on (see urgencyRank() below), so
     * they can never disagree with each other the way the badge and the
     * old wall-clock countdown used to.
     */
    public function realSecondsRemaining(): int
    {
        if (!$this->sla_expires_at) {
            return 0;
        }

        return app(\App\Services\BusinessHoursService::class)
            ->businessSecondsRemaining(now(), $this->sla_expires_at);
    }

    /** Below this many real seconds left, the badge reads "Urgent" regardless of window size. */
    private const URGENT_THRESHOLD_SECONDS = 1800; // 30 minutes

    /** Below this many real seconds left (and above the Urgent threshold), the badge reads "Normal". */
    private const NORMAL_THRESHOLD_SECONDS = 7200; // 2 hours

    /**
     * Urgent=1, Normal=2, Low=3, Expired=4 — absolute thresholds against
     * realSecondsRemaining(), chosen relative to the SLA window's actual
     * range (a flat 15 minutes at the shortest, capped at 6 hours at the
     * longest — see WorkflowService's tieredApproverSlaMinutes()): 30
     * minutes left is "about to run out" no matter how big the original
     * window was, and 2 hours left is already more than a short window's
     * ENTIRE budget, so it's still a meaningful cutoff for those too.
     */
    public function urgencyRank(): int
    {
        $remaining = $this->realSecondsRemaining();

        return match(true) {
            $remaining <= 0 => 4,
            $remaining <= self::URGENT_THRESHOLD_SECONDS => 1,
            $remaining <= self::NORMAL_THRESHOLD_SECONDS => 2,
            default => 3,
        };
    }

    public function urgencyLabel(): string
    {
        return [1 => 'Urgent', 2 => 'Normal', 3 => 'Low', 4 => 'Expired'][$this->urgencyRank()];
    }

    /**
     * Section 3: once escalated, the assignment leaves the approver's own
     * queue — it's now the Admin's to resolve via the SLA override queue.
     */
    public function scopePendingFor($query, $userId)
    {
        return $query->where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', false);
    }
}