<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
    
    /**
 * POST /api/trips/{reference}/proof
 * Save proof of delivery and complete the trip.
 */
public function submitProof(Request $request, string $reference)
{
    $data = $request->validate([
        'photo' => ['required', 'image', 'max:10240'],
        'confirmed_amount' => ['required', 'numeric', 'min:0'],
    ]);

    $user = $request->user();

    $trip = Trip::whereHas(
        'cashRequest',
        fn ($query) => $query->where('reference', $reference)
    )->firstOrFail();

    $isTeamMember = DB::table('team_members')
        ->where('team_id', $trip->team_id)
        ->where('user_id', $user->id)
        ->exists();

    abort_unless(
        $user->is_delivery_member && $isTeamMember,
        403,
        'You are not a member of the team assigned to this trip.'
    );

    abort_unless(
        $trip->status === 'in_transit',
        409,
        'Only trips in transit can submit proof of delivery.'
    );

    // Store the image on Laravel's private local disk.
    $photoPath = $request->file('photo')->store('handover-proofs', 'local');

    try {
        DB::transaction(function () use ($trip, $user, $data, $photoPath) {
            $now = now();

            $proofId = DB::table('handover_proofs')->insertGetId([
                'cash_request_id' => $trip->cash_request_id,
                'stage' => 'dropoff',
                'confirmed_amount' => $data['confirmed_amount'],
                'submitted_by' => $user->id,
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('handover_photos')->insert([
                'handover_proof_id' => $proofId,
                'path' => $photoPath,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $trip->update([
                'status' => 'completed',
                'completed_at' => $now,
            ]);
        });
    } catch (\Throwable $e) {
        // Remove the stored file if saving the database records failed.
        Storage::disk('local')->delete($photoPath);

        throw $e;
    }

    return response()->json([
        'message' => 'Proof of delivery submitted successfully.',
        'reference' => $reference,
        'status' => 'completed',
    ]);
}
}
