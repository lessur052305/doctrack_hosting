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
 * Section 4/5: true event-driven SLA escalation. Dispatched with a delay
 * set to exactly the assignment's sla_expires_at, so it fires the instant
 * the deadline hits — no polling interval, no dependency on someone
 * loading a page. This replaces the periodic sweep as the PRIMARY
 * detection mechanism; workflow:check-parallel-slas stays wired into the
 * scheduler as a backstop only, in case a job is ever lost (e.g. the queue
 * worker was down when it should have fired).
 *
 * $expectedSlaExpiresAt is captured at dispatch time, not re-read from the
 * DB when the job runs — this is what makes recalculation-safe: whenever
 * an SLA deadline changes (business-hours/holiday calendar edits — see
 * WorkflowService::recalculatePendingSlaDeadlines()), a NEW job is
 * dispatched for the new deadline. The OLD job still fires at its
 * original (now stale) time, but on execution finds the assignment's
 * current sla_expires_at no longer matches what it was scheduled for, and
 * silently no-ops instead of escalating incorrectly.
 */
class EscalateAssignmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $assignmentId,
        private Carbon $expectedSlaExpiresAt,
    ) {
    }

    public function handle(SlaService $sla): void
    {
        $assignment = DocumentAssignment::find($this->assignmentId);

        // Second-precision string comparison, not equalTo(): sla_expires_at
        // round-trips through a MySQL `datetime` column (whole-second
        // precision), but $expectedSlaExpiresAt is the original in-memory
        // Carbon value from the dispatch call site, which can carry
        // microseconds — equalTo() would spuriously fail on that mismatch
        // even when the deadline genuinely hasn't changed.
        if (!$assignment
            || $assignment->individual_status !== 'pending'
            || $assignment->escalated_to_admin
            || !$assignment->sla_expires_at
            || $assignment->sla_expires_at->format('Y-m-d H:i:s') !== $this->expectedSlaExpiresAt->format('Y-m-d H:i:s')
        ) {
            return; // resolved, already escalated, or a newer deadline superseded this job
        }

        $sla->escalate($assignment);
    }
}
