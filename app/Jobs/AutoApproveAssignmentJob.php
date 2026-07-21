<?php

namespace App\Jobs;

use App\Models\DocumentAssignment;
use App\Services\SlaService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Event-driven counterpart to EscalateAssignmentJob, one step later in the
 * chain: once an assignment is escalated to Admin, this fires exactly when
 * the Admin grace window (config('sla.admin_grace_hours')) lapses, instead
 * of waiting for the next 5-minute sla:check poll. sla:check stays wired
 * into the scheduler as a backstop only, same relationship
 * workflow:check-parallel-slas has to EscalateAssignmentJob.
 *
 * $expectedGraceExpiresAt is captured at dispatch time (escalated_at + grace
 * hours), not re-read from the DB when the job runs — if the assignment is
 * ever re-escalated (shouldn't currently happen, but mirrors the staleness
 * guard used for SLA recalculation on the escalation job) a new job with a
 * new deadline would supersede this one, which then no-ops on stale data.
 */
class AutoApproveAssignmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $assignmentId,
        private Carbon $expectedGraceExpiresAt,
    ) {
    }

    public function handle(SlaService $sla): void
    {
        $assignment = DocumentAssignment::find($this->assignmentId);

        if (!$assignment
            || $assignment->individual_status !== 'pending'
            || !$assignment->escalated_to_admin
            || $assignment->admin_override_at
            || !$assignment->escalated_at
        ) {
            return; // resolved, overridden, or never actually escalated
        }

        $graceExpiresAt = $assignment->escalated_at->copy()->addHours(config('sla.admin_grace_hours', 12));
        if ($graceExpiresAt->format('Y-m-d H:i:s') !== $this->expectedGraceExpiresAt->format('Y-m-d H:i:s')) {
            return; // a newer escalation superseded this job
        }

        // Belt-and-suspenders: the queue's own delay is what's supposed to
        // guarantee this doesn't run early, but the 'sync' queue driver
        // (used in tests — see phpunit.xml) ignores delay() and runs jobs
        // immediately on dispatch. Without this check, calling escalate()
        // in a test would instantly auto-approve the assignment instead of
        // waiting for the grace window, unlike real queue drivers.
        if (now()->lt($graceExpiresAt)) {
            return;
        }

        $sla->autoApproveOne($assignment);
    }
}
