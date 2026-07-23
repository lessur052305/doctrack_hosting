<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired the instant an account clicks its verification link (see
 * AuthController::verifyEmail()) — pushes the Admin Users page's
 * "Unverified" badge away live, instead of only updating on a manual
 * reload. Reuses the existing 'admin-dashboard' channel (already
 * authorized for any admin, see routes/channels.php) with its own event
 * name, rather than a new channel — this is the same "one shared
 * admin-relevant channel, distinct event names per concern" pattern
 * already used for DocumentStatusChanged on the same channel.
 */
class UserVerified implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin-dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'user.verified';
    }

    public function broadcastWith(): array
    {
        return ['user_id' => $this->user->user_id];
    }
}
