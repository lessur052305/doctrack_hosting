<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AdminViolation
 * ---------------
 * The Admin-side counterpart to SlaViolation — both cases represent
 * "Admin, as the fallback-responsible party for this seat, missed a
 * deadline," attributed to the Admin role collectively (this queue isn't
 * individually owned the way an approver's own assignment is), not any
 * one person:
 *
 *   - 'missed_approval': a stage had no eligible approver — auto-approved
 *     immediately, no waiting period, no deadline to miss (see
 *     SlaService::autoApproveNoEligibleApprover(), called from
 *     WorkflowService::assignStage()/autoApproveDeactivatedSeat()). The
 *     name is a holdover from an earlier design where this only fired
 *     after Admin's own fallback deadline passed unattended; kept
 *     unchanged so historical rows and existing report filters don't
 *     need migrating. Always created already resolved (resolved_at =
 *     first_violated_at) since the auto-approval happens in the same
 *     instant the violation does — there's nothing further to wait on.
 *
 *   - 'late_review': an auto-approved stage (either kind above, or a
 *     real approver's own miss) sat past its 6-hour review window
 *     without Admin actually reviewing it. Created the moment that
 *     window first lapses, stays open (resolved_at null) while
 *     unreviewed — notification_count/last_notified_at track the
 *     capped hourly follow-ups (see SlaService::trackLateReviews()) —
 *     and resolves the moment Admin actually confirms/disputes it (see
 *     AdminController::reviewAutoApproval()).
 *
 *   - 'late_ml_review': RETIRED — a low-confidence classification used to
 *     sit past its own 6-hour review window without Admin confirming/
 *     correcting its category. Classification confidence no longer holds
 *     a document for manual review at all (see WorkflowService::ingest()'s
 *     $isAmbiguous docblock), so this type is never created anymore; kept
 *     here only so any pre-existing historical row of this type still
 *     resolves to something meaningful.
 */
class AdminViolation extends Model
{
    protected $primaryKey = 'violation_id';

    protected $fillable = [
        'document_id', 'assignment_id', 'violation_type', 'stage_name',
        'first_violated_at', 'last_notified_at', 'notification_count', 'resolved_at',
    ];

    protected $casts = [
        'first_violated_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    public function assignment()
    {
        return $this->belongsTo(DocumentAssignment::class, 'assignment_id', 'assignment_id');
    }

    /** Hours overdue as of now (if still open) or as of resolution (if closed) — the single number both the badge and the report display. */
    public function hoursOverdue(): int
    {
        $until = $this->resolved_at ?? now();

        return (int) floor($this->first_violated_at->diffInHours($until));
    }
}
