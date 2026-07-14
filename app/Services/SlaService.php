<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\NotificationRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
    /** Hours an Admin has to act after escalation before auto-approval fires. */
    private const ADMIN_GRACE_HOURS = 12;

    public function __construct(private WorkflowService $workflow)
    {
    }

    public function sweep(): array
    {
        return ['auto_approved' => $this->autoApproveUnresolved()];
    }

    /**
     * Escalated + Admin grace window also elapsed -> system resolves the
     * stage automatically via WorkflowService::completeStage(), which
     * advances/finalizes the document exactly as a human approval would.
     */
    private function autoApproveUnresolved(): int
    {
        $count = 0;
        $graceCutoff = now()->subHours(self::ADMIN_GRACE_HOURS);

        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->where('sla_expires_at', '<=', $graceCutoff)
            ->with(['document', 'stage'])
            ->get()
            ->unique('document_id')
            ->each(function (DocumentAssignment $assignment) use (&$count) {
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

                    $assignment->acted_at = now();
                    $assignment->save();

                    $this->workflow->completeStage($assignment, 'approved', true);
                });
                $count++;
            });

        return $count;
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
                "An Admin override was applied to your document '{$document->title}' ({$decision}).");
        });
    }
}