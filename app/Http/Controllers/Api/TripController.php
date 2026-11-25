<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRequest;
use App\Models\Trip;
use App\Models\TripLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TripController extends Controller
{
    public function arrive(Request $request, string $reference)
    {
        $data = $request->validate([
            'stage' => 'required|in:pickup,dropoff',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        DB::transaction(function () use ($request, $reference, $data) {
            $cashRequest = CashRequest::where('reference', $reference)->lockForUpdate()->firstOrFail();
            $trip = $cashRequest->trip()->lockForUpdate()->firstOrFail();
            $user = $request->user();

            abort_unless($user->is_delivery_member && $trip->accepted_at
                && $trip->team->members()->whereKey($user->id)->exists(), 403,
                'You must belong to the accepted delivery team.');

            $pickup = $data['stage'] === 'pickup';
            $expectedStatus = $pickup ? 'assigned' : 'in_transit';
            abort_unless($trip->status === $expectedStatus, 409, 'This trip is no longer at that stage.');

            $column = $pickup ? 'arrived_pickup_at' : 'arrived_dropoff_at';
            if ($trip->{$column}) {
                return;
            }

            $atBranch = ($cashRequest->type === 'deposit') === $pickup;
            $destination = $atBranch ? $cashRequest->branch : Branch::where('is_hq', true)->first();
            abort_unless($destination && $destination->lat !== null && $destination->lng !== null
                && $destination->handover_radius > 0, 422, 'The handover location is not configured.');

            $latitude = deg2rad((float) $data['latitude']);
            $destinationLatitude = deg2rad((float) $destination->lat);
            $longitudeDelta = deg2rad((float) $data['longitude'] - (float) $destination->lng);
            $a = sin(($latitude - $destinationLatitude) / 2) ** 2
                + cos($latitude) * cos($destinationLatitude) * sin($longitudeDelta / 2) ** 2;
            $distance = 6371000 * 2 * asin(sqrt(min(1, $a)));

            abort_if($distance > $destination->handover_radius, 422, 'Move closer to the handover location.');
            $trip->update([$column => now()]);
        });

        return response()->noContent();
    }

    /**
     * POST /api/trips/{reference}/location
     * Receive a GPS location from an assigned delivery team member.
     */
    public function updateLocation(Request $request, string $reference)
    {
        $data = $request->validate([
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();

        $trip = Trip::whereHas(
            'cashRequest',
            fn ($query) => $query->where('reference', $reference)
        )->firstOrFail();

        // Verify that the user belongs to the assigned delivery team.
        $isTeamMember = DB::table('team_members')
            ->where('team_id', $trip->team_id)
            ->where('user_id', $user->id)
            ->exists();

        abort_unless(
            $user->is_delivery_member && $isTeamMember,
            403,
            'You are not a member of the team assigned to this trip.'
        );

        // Only track trips that have actually started.
        abort_unless(
            $trip->status === 'in_transit',
            409,
            'GPS updates are only accepted for trips in transit.'
        );

        $recordedAt = now();

        DB::transaction(function () use ($trip, $data, $recordedAt) {
            $trip->update([
                'current_lat'  => $data['latitude'],
                'current_lng'  => $data['longitude'],
                'last_ping_at' => $recordedAt,
            ]);
            TripLocation::create([
                'trip_id'     => $trip->id,
                'lat'         => $data['latitude'],
                'lng'         => $data['longitude'],
                'accuracy_m'  => $data['accuracy'] ?? null,
                'recorded_at' => $recordedAt,
            ]);
        });
        
        return response()->json([
            'message' => 'Location updated successfully.',
            'reference' => $reference,
            'location' => [
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'accuracy' => $data['accuracy'] ?? null,
                'recorded_at' => $recordedAt->toIso8601String(),
            ],
        ]);
        $isStale = $trip->status === 'in_transit'&& ( !$trip->last_ping_at || $trip->last_ping_at->lt(now()->subMinutes(2)) );
    }
    
}
