<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastException;

/**
 * A live push is a convenience layered on top of state that is already saved
 * (a notification row, an audit-log row, a status change) — every screen that
 * receives one also has a polling fallback. So a broadcast server that is
 * unreachable or misconfigured must never be allowed to abort the action that
 * triggered it. Left as-is, the framework's broadcaster throws, and because
 * most of this app's events broadcast synchronously (ShouldBroadcastNow), a
 * Reverb outage rolled back SLA auto-approvals mid-transaction and stopped the
 * whole sweep at the first affected seat.
 *
 * The failure is still reported (it shows up in the logs) — it just no longer
 * propagates.
 */
class FailureTolerantBroadcaster extends PusherBroadcaster
{
    public function broadcast(array $channels, $event, array $payload = [])
    {
        try {
            parent::broadcast($channels, $event, $payload);
        } catch (BroadcastException $e) {
            report($e);
        }
    }
}
