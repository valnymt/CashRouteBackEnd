<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id'       => $this->employee_id,
            'name'     => $this->name,
            'username' => $this->username,
            'role'     => $this->role->name,
            'mobile'   => $this->phone ?? '',
            'email'    => $this->email,
            'status'   => $this->is_locked ? 'Locked' : 'Active',
            'branchId' => $this->branch?->code,
            'teamId'   => $this->teams->first() ? (string) $this->teams->first()->id : null,
        ];
    }
}
