<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a chat message is sent — pushes to the recipient's own
 * channel, the same 'user.{id}' private channel the notification bell
 * already uses (see NotificationBroadcast), so the chat widget's unread
 * badge / open thread updates instantly without a new channel. A distinct
 * event name keeps it independent of 'notification.created' on that same
 * shared channel.
 */
class ChatMessageSent implements ShouldBroadcastNow
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
        return 'chat.message.sent';
    }
}
