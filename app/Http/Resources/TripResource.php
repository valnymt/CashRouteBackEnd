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
                'handoverRadius' => (int) $location->handover_radius,
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

        $verifiedOtps = $cr?->relationLoaded('verifiedHandoverOtps')
            ? $cr->verifiedHandoverOtps->keyBy('stage')
            : collect();
        $pickupName = $pickup?->name ?? 'pickup location';
        $dropoffName = $dropoff?->name ?? 'drop-off location';
        $pickupVerified = $verifiedOtps->get('pickup')?->verified_at;
        $dropoffVerified = $verifiedOtps->get('dropoff')?->verified_at;

        $events = collect([
            ['Request created', $cr?->created_at],
            ['Request approved', $cr?->approved_at],
            ['Team assigned', $this->assigned_at],
            ['Team accepted', $this->accepted_at],
            ["Arrived at {$pickupName} (pickup)", $this->arrived_pickup_at],
            ['Pickup OTP verified', $pickupVerified],
            ["Departed {$pickupName}", $started],
            ["Arrived at {$dropoffName} (drop-off)", $this->arrived_dropoff_at],
            ['Drop-off OTP verified', $dropoffVerified],
            ["Completed delivery to {$dropoffName}", $completed],
            ['Request cancelled', $this->status === 'cancelled' ? $cr?->cancelled_at : null],
        ])->filter(fn ($event) => $event[1] !== null)
            ->sortBy(fn ($event) => $event[1]->getTimestamp())
            ->map(fn ($event) => ['label' => $event[0], 'at' => $event[1]->toIso8601String()])
            ->values();

        return [
            'timeline' => $events,
            'arrivedPickupAt' => $this->arrived_pickup_at?->toIso8601String(),
            'arrivedDropoffAt' => $this->arrived_dropoff_at?->toIso8601String(),
            'pickupVerifiedAt' => $pickupVerified?->toIso8601String(),
            'dropoffVerifiedAt' => $dropoffVerified?->toIso8601String(),
            'completedAt' => $completed?->toIso8601String(),
            // The human-readable request reference is not the database primary key.
            // Mobile OTP routes use Laravel route-model binding and need this numeric ID.
            'id' => $cr?->reference,
            'cashRequestId' => $cr?->id ? (int) $cr->id : null,
            //
            'status' => ucfirst(str_replace('_', ' ', $this->status)),
            'branch' => $branch?->name,
            // 'date' => $completed?->format('Y-m-d')
            //     ?? $started?->format('Y-m-d')
            //     ?? $cr?->requested_date?->format('Y-m-d'),
            'date' => $completed?->format('Y-m-d')
                    ?? $started?->format('Y-m-d')
                    ?? $this->created_at?->format('Y-m-d')
                    ?? $cr?->requested_date?->format('Y-m-d'),

            'branchId' => $branch?->code,
            'type' => $cr?->type === 'deposit' ? 'Deposit' : 'Withdrawal',

            'pickup' => $formatLocation($pickup),
            'dropoff' => $formatLocation($dropoff),
            'currentLatitude' => $this->current_lat !== null
                ? (float) $this->current_lat
                : null,
            'currentLongitude' => $this->current_lng !== null
                ? (float) $this->current_lng
                : null,
            'lastPingAt' => $this->last_ping_at?->toIso8601String(),
            'teamId' => $this->team_id !== null ? (string) $this->team_id : null,
            'amount' => (float) ($cr?->amount ?? 0),
            'currency' => $cr?->currency ?? 'MMK',

            'durationMin' => ($started && $completed)
                ? (int) abs($started->diffInMinutes($completed))
                : 0,

            'onTime' => (bool) ($this->is_on_time ?? false),
            'proof_of_delivery' => $this->when($cr?->relationLoaded('handoverProofs'), function () use ($cr) {
                $proof = $cr->handoverProofs->first();
                return $proof ? [
                    'confirmed_amount' => $proof->confirmed_amount,
                    'receiver_name' => $proof->receiver_name,
                    'submitted_at' => $proof->submitted_at->toIso8601String(),
                    'photo_ids' => $proof->photos->modelKeys(),
                    'has_signature' => (bool) $proof->signature_path,
                ] : null;
            }),
        ];
    }
}
