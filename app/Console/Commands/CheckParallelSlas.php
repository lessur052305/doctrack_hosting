<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use Illuminate\Console\Command;

/**
 * Run via: php artisan workflow:check-parallel-slas
 * Scheduled every 5 minutes in bootstrap/app.php.
 *
 * Flags individually expired PENDING parallel approver assignments by
 * setting escalated_to_admin = true. Each assignment row is checked and
 * flagged independently, so one slow approver's missed SLA window never
 * affects their on-time parallel peers — their own rows are untouched.
 *
 * This is the first half of the Section 5 safety net; the second half
 * (auto-approval after an Admin grace window) is handled by the existing
 * `sla:check` command via SlaService.
 */
class CheckParallelSlas extends Command
{
    protected $signature = 'workflow:check-parallel-slas';
    protected $description = 'Flag individual parallel approver assignments whose SLA window has expired.';

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
                "Parallel approver assignment #{$assignment->assignment_id} (stage '{$assignment->stage->stage_name}') " .
                'exceeded its SLA window and was flagged for Admin escalation.');
        }

        $this->info("{$expired->count()} parallel assignment(s) flagged as escalated_to_admin.");

        return self::SUCCESS;
    }
}