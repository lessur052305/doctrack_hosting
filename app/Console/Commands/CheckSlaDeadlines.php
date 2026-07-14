<?php

namespace App\Console\Commands;

use App\Services\SlaService;
use Illuminate\Console\Command;

/**
 * Run via: php artisan sla:check
 * Scheduled every 5 minutes in bootstrap/app.php, alongside
 * workflow:check-parallel-slas (see README.md for the cron entry needed
 * to drive Laravel's scheduler in production).
 *
 * This command only handles the SECOND half of the Section 5 safety net:
 * auto-approving a stage if an Admin doesn't act within the grace window
 * after escalation. The escalation itself (flagging an individual expired
 * assignment) is handled by workflow:check-parallel-slas.
 */
class CheckSlaDeadlines extends Command
{
    protected $signature = 'sla:check';
    protected $description = 'Auto-approve stages whose escalated assignments have gone unresolved past the Admin grace window.';

    public function handle(SlaService $sla): int
    {
        $result = $sla->sweep();

        $this->info("SLA sweep complete: {$result['auto_approved']} document(s) auto-approved after the Admin grace window elapsed.");

        return self::SUCCESS;
    }
}