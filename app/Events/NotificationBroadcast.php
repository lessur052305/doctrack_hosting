<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever NotificationRecord::send() creates a notification —
 * pushes to that recipient's own channel over Reverb so the notification
 * bell updates instantly regardless of which role they are, rather than
 * on the next poll cycle. Named distinctly from the NotificationRecord
 * model it wraps, to keep "the database row" and "the live push" clearly
 * separate concepts.
 */
class NotificationBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $recipientId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }
}
