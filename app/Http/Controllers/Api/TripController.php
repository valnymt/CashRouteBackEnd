<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TripController extends Controller
{
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

        DB::transaction(function () use ($trip, $data) {
            $recordedAt = now();

            $trip->update([
                'current_lat'  => $data['latitude'],
                'current_lng'  => $data['longitude'],
                'last_ping_at' => $recordedAt,
            ]);

            TripLocation::create([
                'trip_id'    => $trip->id,
                'lat'        => $data['latitude'],
                'lng'        => $data['longitude'],
                'accuracy_m' => $data['accuracy'] ?? null,
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
                'recorded_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
