<?php

namespace App\Console\Commands;

use App\Models\DocumentAssignment;
use App\Services\SlaService;
use Illuminate\Console\Command;

/**
 * Run via: php artisan workflow:check-parallel-slas
 * Scheduled every 5 minutes in bootstrap/app.php.
 *
 * Command name is historical — each document is now routed to exactly one
 * approver per stage (single-assignment, load-balanced routing; see
 * WorkflowService), so there are no longer parallel sibling assignments to
 * reconcile. This command still flags any individually expired PENDING
 * assignment by setting escalated_to_admin = true.
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
