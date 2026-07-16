<?php

namespace App\Events;

use App\Models\DocumentAssignment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired over Reverb whenever a DocumentAssignment is created OR an
 * existing one's status changes (see DocumentAssignment::booted()), so an
 * approver's queue updates without waiting on a poll cycle.
 *
 * $targetApproverId is deliberately separate from $assignment->user_id:
 * the approver dashboard shows the FULL stage pipeline for a document for
 * context (see approver/partials/queue.blade.php's <x-workflow-stage-list>),
 * not just the viewer's own stage — so when Approver B decides their
 * stage, Approver A (who holds a *different* stage on that same document)
 * also needs to be told to refresh, even though nothing about Approver
 * A's own assignment changed. DocumentAssignment::booted() fires one of
 * these per approver who has any assignment on the affected document.
 */
class AssignmentRouted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $targetApproverId;

    public function __construct(public DocumentAssignment $assignment, ?int $targetApproverId = null)
    {
        $this->targetApproverId = $targetApproverId ?? $assignment->user_id;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('approver.' . $this->targetApproverId)];
    }

    public function broadcastAs(): string
    {
        return 'assignment.routed';
    }

    public function broadcastWith(): array
    {
        return ['assignment_id' => $this->assignment->assignment_id];
    }
}
