<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cr = $this->cashRequest;
        $started = $this->started_at;
        $completed = $this->completed_at;

        // Get the request branch and HQ branch.
        $branch = $cr?->branch;
        $hq = $request->attributes->get('hqBranch');

        // Convert a branch into the location format used by the mobile app.
        $formatLocation = function ($location) {
            if (!$location) {
                return null;
            }

            return [
                'name' => $location->name,
                'code' => $location->code,
                'address' => $location->address,
                'latitude' => $location->lat,
                'longitude' => $location->lng,
            ];
        };

        // Determine the delivery direction.
        if ($cr?->type === 'deposit') {
            $pickup = $branch;
            $dropoff = $hq;
        } else {
            $pickup = $hq;
            $dropoff = $branch;
        }

        return [
            'id' => $cr?->reference,
            'date' => $completed?->format('Y-m-d')
                ?? $started?->format('Y-m-d')
                ?? $cr?->requested_date?->format('Y-m-d'),

            'branchId' => $branch?->code,
            'type' => $cr?->type === 'deposit' ? 'Deposit' : 'Withdrawal',

            'pickup' => $formatLocation($pickup),
            'dropoff' => $formatLocation($dropoff),

            'teamId' => (string) $this->team_id,
            'amount' => (float) ($cr?->amount ?? 0),
            'currency' => $cr?->currency ?? 'MMK',

            'durationMin' => ($started && $completed)
                ? (int) abs($started->diffInMinutes($completed))
                : 0,

            'onTime' => (bool) ($this->is_on_time ?? false),
        ];
    }
}
