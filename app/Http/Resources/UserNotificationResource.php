<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Notification in the shape the portal expects (src/types/index.ts → Notification). */
class UserNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'    => 'N-' . $this->id,
            'title' => $this->title,
            'body'  => $this->body,
            'at'    => $this->created_at?->toIso8601String(),
            'read'  => $this->read_at !== null,
            'link'  => $this->link,
        ];
    }
}
