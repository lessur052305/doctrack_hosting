<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired the instant an Admin deactivates a user (see
 * AdminController::toggleUser()), so that user's own open session is
 * kicked out immediately instead of only failing on their next request.
 *
 * Broadcasts on the same 'user.{id}' channel every authenticated page
 * already subscribes to for the notification bell (see app.js) — no new
 * channel authorization needed, it's already open on every page for
 * every role the moment they're logged in.
 */
class AccountDeactivated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $userId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'account.deactivated';
    }
}
