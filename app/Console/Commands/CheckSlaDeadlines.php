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
 * The actual auto-approval decision (a real approver's own missed
 * deadline) is event-driven — see EscalateAssignmentJob and
 * SlaService::autoApproveMissedDeadline(); workflow:check-parallel-slas is
 * its scheduled backstop. A stage with no eligible approver at all
 * auto-approves immediately at routing time instead (see
 * WorkflowService::assignStage()), so there's no deadline for it to
 * miss here either. This command handles the things that genuinely need
 * to run on a schedule: outage detection/compensation, the Admin's
 * late-review reminders (see trackLateReviews()), and the one-time
 * "final call" reminder to an approver whose deadline is about to lapse.
 */
class CheckSlaDeadlines extends Command
{
    protected $signature = 'sla:check';

    protected $description = 'Detects/compensates for outages and follows up on auto-approvals sitting unreviewed past their window.';

    public function handle(SlaService $sla): int
    {
        $result = $sla->sweep();

        $outageNote = $result['outage_detected']
            ? " Outage detected — {$result['deadlines_compensated']} deadline(s) compensated."
            : '';

        $this->info("SLA sweep complete: {$result['late_review_reminders_sent']} late-review reminder(s) sent, ".
            "{$result['urgent_approver_reminders_sent']} approver final-call reminder(s) sent.{$outageNote}");

        return self::SUCCESS;
    }
}
