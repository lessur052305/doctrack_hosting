<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever either ML model finishes training — the document
 * classifier (ClassificationService::train(), both the manual admin
 * "Train Model" action and AutoTrainClassifier's automatic retrains) or
 * an approval-time estimator (ApprovalTimeMlService::trainFor(), run on
 * a schedule). Both update the same ML Training admin page's "Active
 * Model" / "Training History" / "Estimated Approval Time" panels, so one
 * shared event is enough — the receiving page just re-fetches its own
 * fragment rather than trusting a payload, same pattern as
 * DocumentStatusChanged.
 *
 * ShouldBroadcastNow so an admin sitting on the page sees a fresh
 * "Train Model" result instantly, and auto-retrains (however they're
 * triggered) push the same update without anyone needing to reload.
 */
class MlModelTrained implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin-dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ml.model-trained';
    }
}
