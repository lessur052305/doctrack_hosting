<?php

namespace App\Services;

use App\Events\DocumentStatusChanged;
use App\Jobs\AutoApproveAssignmentJob;
use App\Mail\DocumentDecisionMail;
use App\Mail\SlaEscalationMail;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * SlaService
 * -----------
 * The second half of the Section 5 safety net (the first half — flagging
 * an individual expired assignment as escalated_to_admin — is handled by
 * the `workflow:check-parallel-slas` command). Since each document is now
 * routed to exactly one approver per stage (single-assignment,
 * load-balanced routing — see WorkflowService), each escalation
 * corresponds to exactly one stuck assignment, with no sibling rows to
 * reconcile.
 *
 *   escalated_to_admin = true (set by workflow:check-parallel-slas)
 *     -> Admin may override: approve/reject on the approver's behalf
 *     -> if Admin is ALSO unresponsive for a grace window
 *        -> System Auto-Approval, with a high-priority notification sent
 *           to both Admin and Approver roles.
 *
 * Intended to run every few minutes via the scheduler alongside
 * workflow:check-parallel-slas (see bootstrap/app.php and README.md).
 */
class SlaService
{
    public function __construct(private WorkflowService $workflow)
    {
    }

    public function sweep(): array
    {
        return ['auto_approved' => $this->autoApproveUnresolved()];
    }

    /**
     * Flags a single expired-but-not-yet-escalated assignment: sets
     * escalated_to_admin, logs the breach to sla_violations, and notifies
     * Admins (in-app + email). This is the same logic the scheduled
     * `workflow:check-parallel-slas` command runs in bulk — factored out
     * here so it can ALSO be triggered on-demand (see ApprovalController)
     * the moment someone touches a since-expired assignment, rather than
     * depending entirely on the next cron tick. Without this, an approver
     * could still approve/reject an assignment whose SLA had already
     * lapsed, simply because the periodic sweep hadn't run yet.
     */
    public function escalate(DocumentAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $escalatedAt = now();
            $assignment->escalated_to_admin = true;
            $assignment->escalated_at = $escalatedAt;
            $assignment->save();

            AuditLog::record(null, $assignment->document_id, 'sla_escalation',
                "Approver assignment #{$assignment->assignment_id} (stage '{$assignment->stage->stage_name}') " .
                'exceeded its SLA window and was flagged for Admin escalation.');

            // Event-driven auto-approval (mirrors EscalateAssignmentJob): fires
            // exactly when the Admin grace window lapses, instead of waiting
            // for the next 5-minute sla:check poll. sla:check stays wired into
            // the scheduler as a backstop only (see bootstrap/app.php).
            $graceExpiresAt = $escalatedAt->copy()->addHours(config('sla.admin_grace_hours', 12));
            AutoApproveAssignmentJob::dispatch($assignment->assignment_id, $graceExpiresAt)->delay($graceExpiresAt);

            // abs()+round(): Carbon 3's diffInMinutes() returns a signed
            // float even with the default $absolute param, so the sign and
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

            foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
                NotificationRecord::send($admin->user_id, $assignment->document_id,
                    "SLA breach: '{$assignment->document->title}' at stage '{$assignment->stage->stage_name}' " .
                    '(approver: ' . ($assignment->approver->full_name ?? 'unassigned') . ') needs Admin attention.',
                    'high');

                if ($admin->email) {
                    Mail::to($admin->email)->queue(new SlaEscalationMail($assignment));
                }
            }

            // DocumentAssignment::booted() only broadcasts on an
            // individual_status change — escalating to Admin never touches
            // that column, so without this the Admin dashboard's SLA alert
            // widget would only learn about the breach via a bell
            // notification, never a live list refresh. Reuses the same
            // event/channel the dashboard already listens on.
            event(new DocumentStatusChanged($assignment->document));
        });
    }

    /**
     * Deactivation handoff (Feature) — an approver was deactivated while
     * holding a pending assignment, and WorkflowService::
     * findReplacementApprover() found nobody else eligible for that stage.
     * Escalates to Admin immediately rather than waiting for the SLA
     * deadline to actually pass (which may still be hours/days away).
     *
     * Deliberately does NOT create an SlaViolation row, unlike escalate()
     * above — the original approver didn't fail to act in time, they were
     * deactivated before their deadline hit, so counting this as a breach
     * would unfairly skew the Violations Report's stats (breach counts,
     * Top Approver, etc.). escalation_reason records why this one's
     * different so the SLA Override Queue can show it as such rather than
     * a misleading "SLA Breached" badge.
     */
    public function escalateForReassignmentFailure(DocumentAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $escalatedAt = now();
            $assignment->escalated_to_admin = true;
            $assignment->escalated_at = $escalatedAt;
            $assignment->escalation_reason = 'no_eligible_approver';
            $assignment->save();

            AuditLog::record(null, $assignment->document_id, 'sla_escalation',
                "Approver assignment #{$assignment->assignment_id} (stage '{$assignment->stage->stage_name}') " .
                'escalated to Admin immediately — no eligible replacement approver was available after the ' .
                'original approver\'s account was deactivated.');

            $graceExpiresAt = $escalatedAt->copy()->addHours(config('sla.admin_grace_hours', 12));
            AutoApproveAssignmentJob::dispatch($assignment->assignment_id, $graceExpiresAt)->delay($graceExpiresAt);

            foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
                NotificationRecord::send($admin->user_id, $assignment->document_id,
                    "'{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') needs a new approver — " .
                    'the assigned approver was deactivated and no eligible replacement was available.',
                    'high');

                if ($admin->email) {
                    Mail::to($admin->email)->queue(new SlaEscalationMail($assignment));
                }
            }

            event(new DocumentStatusChanged($assignment->document));
        });
    }

    /**
     * Escalated + Admin grace window also elapsed -> system resolves the
     * stage automatically via WorkflowService::completeStage(), which
     * advances/finalizes the document exactly as a human approval would.
     */
    private function autoApproveUnresolved(): int
    {
        $count = 0;
        $graceCutoff = now()->subHours(config('sla.admin_grace_hours', 12));

        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('sla_expires_at', '<=', $graceCutoff)
            ->with(['document', 'stage'])
            ->get()
            ->unique('document_id')
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                $this->autoApproveOne($assignment);
                $count++;
            });

        return $count;
    }

    /**
     * Shared by the sla:check polling backstop and AutoApproveAssignmentJob
     * (event-driven, fires exactly when the grace window lapses) — same
     * outcome either way, just triggered differently.
     */
    public function autoApproveOne(DocumentAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $document = $assignment->document;

            AuditLog::record(null, $document->document_id, 'auto_approve',
                "System auto-approved stage '{$assignment->stage->stage_name}' after Admin grace window elapsed with no response.");

            NotificationRecord::send($document->originator_id, $document->document_id,
                "Your document '{$document->title}' had a stage auto-approved by the system after an unresolved SLA breach.", 'high');

            foreach (User::whereIn('role', ['admin', 'approver'])->where('is_active', true)->get() as $u) {
                NotificationRecord::send($u->user_id, $document->document_id,
                    "HIGH PRIORITY: '{$document->title}' had a stage auto-approved by the system without human sign-off. Please review.",
                    'high');
            }

            // individual_status and auto_approved must be set here —
            // completeStage() only finalizes the DOCUMENT's
            // global_status; it never touches the assignment's own
            // status (that's the caller's job, same as decide() and
            // adminOverride() already do). Without this, the
            // assignment stays 'pending' forever and would get
            // caught — and re-notified on — every subsequent sweep.
            $assignment->individual_status = 'approved';
            $assignment->auto_approved = true;
            $assignment->acted_at = now();
            $assignment->save();

            $this->workflow->completeStage($assignment, 'approved', true);
        });
    }

    /**
     * Admin manually overrides a stuck assignment (approve or reject).
     * Routed through WorkflowService::completeStage() so the document
     * advances/finalizes exactly as it would from a normal approver decision.
     */
    public function adminOverride(DocumentAssignment $assignment, User $admin, string $decision, ?string $comments = null): void
    {
        DB::transaction(function () use ($assignment, $admin, $decision, $comments) {
            $assignment->admin_override_at = now();
            $assignment->admin_override_by = $admin->user_id;
            $assignment->individual_status = $decision;
            $assignment->comments = $comments;
            $assignment->acted_at = now();
            $assignment->save();

            $document = $assignment->document;
            AuditLog::record($admin->user_id, $document->document_id, 'admin_override',
                "Admin {$admin->full_name} overrode stage '{$assignment->stage->stage_name}' -> {$decision}." . ($comments ? " Notes: {$comments}" : ''));

            $this->workflow->completeStage($assignment, $decision);

            NotificationRecord::send($document->originator_id, $document->document_id,
                "An Admin override was applied to your document '{$document->title}' ({$decision})." .
                ($comments ? " Notes: \"{$comments}\"" : ''));

            if ($document->originator->email) {
                Mail::to($document->originator->email)->queue(
                    new DocumentDecisionMail($document, $decision, $comments, $assignment->stage->stage_name)
                );
            }
        });
    }
}