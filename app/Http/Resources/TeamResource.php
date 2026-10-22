<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Team in the shape the portal expects (src/types/index.ts → Team). */
class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // While on a trip, show which request the team is delivering.
        $trip = $this->status === 'on_trip'
            ? $this->trips()->whereIn('status', ['assigned', 'in_transit'])->with('cashRequest')->latest()->first()
            : null;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'status' => $this->status === 'on_trip' ? 'On trip' : 'Available',
            'currentRequestId' => $trip?->cashRequest?->reference,
            'memberIds' => $this->members->pluck('employee_id')->values(),
            'confirmed' => (bool) $this->is_confirmed,
        ];
    }
}
