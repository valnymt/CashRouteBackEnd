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
                'contactName' => $location->contact_name,
                'contactPhone' => $location->contact_phone,
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

        $proof = $cr?->relationLoaded('handoverProofs') ? $cr->handoverProofs->first() : null;
        $includeActors = $cr?->relationLoaded('auditLogs');
        $actors = [];
        $roster = null;
        if ($includeActors) {
            $assignment = $cr->auditLogs->last(fn ($entry) => in_array($entry->action, ['assign', 'reassign'], true));
            $snapshot = $assignment?->details['roster'] ?? null;
            if (isset($snapshot['team_id'], $snapshot['members']) && (int) $snapshot['team_id'] === (int) $this->team_id) {
                $roster = $snapshot;
            }

            $auditActor = fn ($action, $at) => $at ? $cr->auditLogs->last(fn ($entry) =>
                $entry->action === $action
                && ($entry->details['at'] ?? $entry->created_at?->toIso8601String()) === $at->toIso8601String()
            )?->user?->name : null;
            $otpActor = fn ($stage) => $verifiedOtps->get($stage)?->attemptLogs->first()?->user?->name;

            $actors = [
                'request_created' => $cr->creator?->name,
                'request_approved' => $cr->approver?->name,
                'assigned' => $this->assignedBy?->name,
                'accepted' => $auditActor('accept', $this->accepted_at),
                'pickup_arrival' => $auditActor('arrival.pickup', $this->arrived_pickup_at),
                'pickup_verified' => $otpActor('pickup'),
                'departed' => $started && $pickupVerified && $started->equalTo($pickupVerified) ? $otpActor('pickup') : null,
                'dropoff_arrival' => $auditActor('arrival.dropoff', $this->arrived_dropoff_at),
                'dropoff_verified' => $otpActor('dropoff'),
                'completed' => $proof?->submittedBy?->name,
                'cancelled' => $cr->canceller?->name,
            ];
        }

        $events = collect([
            ['request_created', 'Request created', $cr?->created_at],
            ['request_approved', 'Request approved', $cr?->approved_at],
            ['assigned', 'Team assigned', $this->assigned_at],
            ['accepted', 'Team accepted', $this->accepted_at],
            ['pickup_arrival', "Arrived at {$pickupName} (pickup)", $this->arrived_pickup_at],
            ['pickup_verified', 'Pickup OTP verified', $pickupVerified],
            ['departed', "Departed {$pickupName}", $started],
            ['dropoff_arrival', "Arrived at {$dropoffName} (drop-off)", $this->arrived_dropoff_at],
            ['dropoff_verified', 'Drop-off OTP verified', $dropoffVerified],
            ['completed', "Completed delivery to {$dropoffName}", $completed],
            ['cancelled', 'Request cancelled', $this->status === 'cancelled' ? $cr?->cancelled_at : null],
        ])->filter(fn ($event) => $event[2] !== null)
            ->sortBy(fn ($event) => $event[2]->getTimestamp())
            ->map(fn ($event) => [
                'kind' => $event[0],
                'label' => $event[1],
                'at' => $event[2]->toIso8601String(),
            ] + ($includeActors ? ['actor' => $actors[$event[0]] ?? null] : []))
            ->values();

        return [
            'timeline' => $events,
            'teamRoster' => $this->when($includeActors, $roster),
            'startedAt' => $started?->toIso8601String(),
            'requestedDate' => $cr?->requested_date?->toDateString(),
            'dueAt' => $cr?->due_at?->toIso8601String(),
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
            'proof_of_delivery' => $this->when($cr?->relationLoaded('handoverProofs'), function () use ($proof, $includeActors) {
                return $proof ? [
                    'confirmed_amount' => $proof->confirmed_amount,
                    'receiver_name' => $proof->receiver_name,
                    'submitted_at' => $proof->submitted_at->toIso8601String(),
                    'photo_ids' => $proof->photos->modelKeys(),
                    'has_signature' => (bool) $proof->signature_path,
                ] + ($includeActors ? ['submitted_by' => $proof->submittedBy?->name] : []) : null;
            }),
        ];
    }
}
