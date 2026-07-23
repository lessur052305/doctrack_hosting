<?php

namespace App\Models;

use App\Events\AssignmentRouted;
use App\Events\DocumentStatusChanged;
use Illuminate\Database\Eloquent\Model;

/**
 * Exactly ONE row per document per stage ("single-assignment routing").
 * When a document enters a stage, it is routed to whichever eligible
 * approver in that category currently has the fewest pending assignments
 * (see WorkflowService::selectApproverForStage()) — never to more than one
 * approver at once, so a document can't be picked up or acted on by two
 * approvers simultaneously.
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
        'reassigned_at', 'reassigned_from', 'reassignment_reason',
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
        'reassigned_at' => 'datetime',
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

    /** Seconds remaining before SLA breach — used for the countdown UI. */
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
     * When the system will auto-approve this assignment if no Admin acts —
     * escalated_at + config('sla.admin_grace_hours'). Null once the window
     * no longer applies (already resolved, or never escalated).
     */
    public function adminGraceExpiresAt(): ?\Carbon\Carbon
    {
        if (!$this->escalated_at || $this->individual_status !== 'pending') {
            return null;
        }

        return $this->escalated_at->copy()->addHours(config('sla.admin_grace_hours', 12));
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