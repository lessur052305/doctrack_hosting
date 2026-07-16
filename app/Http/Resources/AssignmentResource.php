<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->assignment_id,
            'document_id' => $this->document_id,
            'document_title' => $this->whenLoaded('document', fn () => $this->document->title),
            'stage_name' => $this->whenLoaded('stage', fn () => $this->stage->stage_name),
            'status' => $this->individual_status,
            'priority_rank' => $this->priority_rank,
            'sla_expires_at' => $this->sla_expires_at?->toIso8601String(),
            'escalated_to_admin' => $this->escalated_to_admin,
            'comments' => $this->comments,
            'acted_at' => $this->acted_at?->toIso8601String(),
        ];
    }
}
