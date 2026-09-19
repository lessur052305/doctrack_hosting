<?php

namespace App\Jobs;

use App\Services\ApprovalTimeMlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Event-driven counterpart to TrainTimeEstimateModels' hourly sweep —
 * dispatched right after a real approval decision lands (see
 * WorkflowService::decide()), scoped to just the ONE (category,
 * department) pair that decision belongs to. Ridge Regression + 5-fold
 * cross-validation (ApprovalTimeMlService::trainFor()) is cheap enough on
 * this app's data volumes to run per-decision as a background job without
 * meaningfully loading the queue worker, and this is strictly better than
 * the old hourly-only cadence: no wasted recomputation on pairs with no
 * new data since the last check, and the model reflects a new decision
 * within seconds instead of up to an hour later.
 *
 * The hourly schedule stays in place as a safety-net fallback (catches a
 * pair that crossed the training floor via a path that didn't fire this
 * event), not the primary trigger anymore.
 */
class RetrainApprovalTimeModel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $category,
        private string $department,
    ) {
    }

    public function handle(ApprovalTimeMlService $timeMl): void
    {
        $timeMl->trainFor($this->category, $this->department);
    }
}
