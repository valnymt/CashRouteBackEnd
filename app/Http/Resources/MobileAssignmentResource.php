<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cashRequest = $this->resource;
        $branch = $cashRequest->branch;
        $hq = $cashRequest->getRelation('hqBranch');
        $team = $cashRequest->trip?->team;

        $isDeposit = strtolower($cashRequest->type) === 'deposit';

        $pickup = $isDeposit ? $branch : $hq;
        $dropoff = $isDeposit ? $hq : $branch;

        return [
            // Keep the numeric ID separate from the display reference.
            'cash_request_id' => $cashRequest->id,
            'reference' => $cashRequest->reference,
            'trip_id' => $cashRequest->trip?->id,

            'type' => ucfirst($cashRequest->type),
            'amount' => (string) $cashRequest->amount,
            'currency' => $cashRequest->currency,
            'date' => $cashRequest->requested_date?->format('Y-m-d'),
            'remarks' => $cashRequest->remarks ?? '',
            'status' => match ($cashRequest->status) {
                'assigned', 'reassigned' => 'Assigned',
                'in_transit' => 'In Transit',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                default => ucfirst($cashRequest->status),
            },

            'team_id' => $cashRequest->trip?->team_id,

            // Actual team and members from the database.
            'team' => $team ? [
                'name' => $team->name,
                'members' => $team->members->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'role' => $member->role?->name,
                    'phone' => $member->phone,
                ])->values(),
            ] : null,

            'pickup' => [
                'name' => $pickup?->name,
                'address' => $pickup?->address,
                'latitude' => $pickup?->lat,
                'longitude' => $pickup?->lng,
                'contact_name' => $pickup?->contact_name,
                'contact_phone' => $pickup?->contact_phone,
            ],

            'dropoff' => [
                'name' => $dropoff?->name,
                'address' => $dropoff?->address,
                'latitude' => $dropoff?->lat,
                'longitude' => $dropoff?->lng,
                'contact_name' => $dropoff?->contact_name,
                'contact_phone' => $dropoff?->contact_phone,
            ],

            'assigned_at' => $cashRequest->trip?->assigned_at?->toIso8601String(),
            'accepted_at' => $cashRequest->trip?->accepted_at?->toIso8601String(),
        ];
    }
}