<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user_id,
            'username' => $this->username,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'role' => $this->role,
            'assigned_category' => $this->assigned_category,
        ];
    }
}
