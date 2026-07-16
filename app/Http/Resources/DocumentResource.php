<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->document_id,
            'title' => $this->title,
            'category' => $this->ml_category,
            'confidence' => $this->ml_confidence,
            'status' => $this->global_status,
            'is_validated' => $this->is_validated,
            'validation_errors' => $this->validation_errors,
            'version_number' => $this->version_number,
            'is_legacy_import' => $this->is_legacy_import,
            'due_date' => $this->due_date?->toIso8601String(),
            'uploaded_at' => $this->upload_date?->toIso8601String(),
            'batch_id' => $this->batch_id,
            'originator' => $this->whenLoaded('originator', fn () => [
                'id' => $this->originator->user_id,
                'full_name' => $this->originator->full_name,
            ]),
            'assignments' => AssignmentResource::collection($this->whenLoaded('assignments')),
        ];
    }
}
