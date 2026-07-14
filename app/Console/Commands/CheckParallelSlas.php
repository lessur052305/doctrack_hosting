<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
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
 * `sla:check` command via SlaService.
 */
class CheckParallelSlas extends Command
{
    protected $signature = 'workflow:check-parallel-slas';
    protected $description = 'Flag individual approver assignments whose SLA window has expired.';

    public function handle(): int
    {
        $expired = DocumentAssignment::where('individual_status', 'pending')
            ->where('escalated_to_admin', false)
            ->where('sla_expires_at', '<', now())
            ->with('stage')
            ->get();

        foreach ($expired as $assignment) {
            $assignment->escalated_to_admin = true;
            $assignment->save();

            AuditLog::record(null, $assignment->document_id, 'sla_escalation',
                "Approver assignment #{$assignment->assignment_id} (stage '{$assignment->stage->stage_name}') " .
                'exceeded its SLA window and was flagged for Admin escalation.');
        }

        $this->info("{$expired->count()} assignment(s) flagged as escalated_to_admin.");

        return self::SUCCESS;
    }
}