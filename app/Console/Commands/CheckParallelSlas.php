<?php

namespace App\Console\Commands;

use App\Models\DocumentAssignment;
use App\Services\SlaService;
use Illuminate\Console\Command;

/**
 * Run via: php artisan workflow:check-parallel-slas
 * Scheduled every 5 minutes in bootstrap/app.php.
 *
 * Command name fits again: every eligible approver is assigned to a stage
 * at once (see WorkflowService::assignStage()), so a stage genuinely can
 * have several parallel sibling assignments in flight simultaneously. This
 * sweep escalates each individually expired PENDING assignment on its own
 * (logs an SlaViolation and auto-approves it — see SlaService::
 * escalateApproverMiss()) with no cross-row coordination; each seat's SLA
 * window is independent of its siblings.
 *
 * This is the first half of the Section 5 safety net; the second half
 * (auto-approval after an Admin grace window) is handled by the existing
 * `sla:check` command via SlaService. The actual per-assignment escalation
 * logic lives in SlaService::escalate() — this command is a bulk periodic
 * sweep, but ApprovalController also calls the same method on-demand so
 * an approver can never act on an assignment past its own SLA window just
 * because this sweep hasn't run yet.
 */
class CheckParallelSlas extends Command
{
    protected $signature = 'workflow:check-parallel-slas';
    protected $description = 'Flag individual approver assignments whose SLA window has expired.';

    public function __construct(private SlaService $sla)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $expired = DocumentAssignment::where('individual_status', 'pending')
            ->where('escalated_to_admin', false)
            ->where('sla_expires_at', '<', now())
            ->with(['stage', 'document', 'approver'])
            ->get();

        foreach ($expired as $assignment) {
            $this->sla->escalate($assignment);
        }

        $this->info("{$expired->count()} assignment(s) flagged as escalated_to_admin.");

        return self::SUCCESS;
    }
}
