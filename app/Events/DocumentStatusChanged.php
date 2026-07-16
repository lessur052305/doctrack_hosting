<?php

namespace App\Events;

use App\Models\DocumentRepository;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a document's global_status actually changes (see
 * DocumentRepository::booted()) — pushes the update to the originator who
 * owns it and to every admin's dashboard over Reverb, so both surfaces
 * update the instant it happens instead of on the next poll.
 *
 * ShouldBroadcastNow, not the queued ShouldBroadcast — this fires
 * synchronously in the same request that changed the status, so there's
 * no queue-worker round trip adding latency to what's supposed to be
 * instant.
 */
class DocumentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DocumentRepository $document)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('originator.' . $this->document->originator_id),
            new PrivateChannel('admin-dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'document.status-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->document->document_id,
            'status' => $this->document->global_status,
        ];
    }
}
