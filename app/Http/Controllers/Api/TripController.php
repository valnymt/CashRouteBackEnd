<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRequest;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TripController extends Controller
{
    public function route(Request $request, string $reference)
    {
        $user = $request->user();
        $trip = Trip::with('cashRequest.branch')
            ->whereHas('cashRequest', fn ($query) => $query->where('reference', $reference))->firstOrFail();
        $member = $trip->hasDeliveryMember($user);
        abort_unless($member || $user->hasPermission('monitoring.view'), 403);
        abort_unless($trip->accepted_at && in_array($trip->status, ['assigned', 'in_transit'], true),
            409, 'Routing is available after trip acceptance.');

        $pickup = $trip->status === 'assigned';
        $atBranch = ($trip->cashRequest->type === 'deposit') === $pickup;
        $stop = $atBranch ? $trip->cashRequest->branch : Branch::where('is_hq', true)->first();
        abort_unless($stop && $stop->lng !== null && $stop->lat !== null, 422, 'The handover stop is missing coordinates.');

        if ($pickup) {
            $data = $request->validate([
                'latitude' => 'nullable|required_with:longitude|numeric|between:-90,90',
                'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180',
            ]);
            $latitude = $member ? ($data['latitude'] ?? null) : null;
            $longitude = $member ? ($data['longitude'] ?? null) : null;
        } else {
            $latitude = $trip->current_lat;
            $longitude = $trip->current_lng;
        }

        if ($latitude === null || $longitude === null) {
            return response()->json(['data' => null])->header('Cache-Control', 'no-store');
        }

        $coordinates = sprintf('%.5F,%.5F;%.5F,%.5F', $longitude, $latitude, $stop->lng, $stop->lat);
        $key = "trip-route:{$trip->id}:{$trip->status}";
        $cached = Cache::get($key);
        if (($cached['coordinates'] ?? null) === $coordinates) {
            return response()->json(['data' => $cached['route']])->header('Cache-Control', 'no-store');
        }

        foreach (['https://router.project-osrm.org', 'https://routing.openstreetmap.de/routed-car'] as $provider) {
            try {
                $response = Http::acceptJson()->connectTimeout(2)->timeout(4)
                    ->get("{$provider}/route/v1/driving/{$coordinates}", [
                        'overview' => 'full',
                        'geometries' => 'geojson',
                    ]);
            } catch (ConnectionException) {
                continue;
            }

            $route = $response->json('routes.0');
            if (!$response->successful() || $response->json('code') !== 'Ok'
                || ($route['geometry']['type'] ?? null) !== 'LineString'
                || count($route['geometry']['coordinates'] ?? []) < 2) {
                continue;
            }

            $result = [
                'geometry' => $route['geometry'],
                'distance' => $route['distance'],
                'duration' => $route['duration'],
            ];
            Cache::put($key, ['coordinates' => $coordinates, 'route' => $result], now()->addMinutes(2));

            return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
        }

        return response()->json(['message' => 'Unable to find a road route from the current GPS position.'], 503);
    }

    public function track(Request $request, string $reference)
    {
        abort_unless($request->user()->hasPermission('monitoring.view'), 403);
        $trip = Trip::whereHas('cashRequest', fn ($query) => $query->where('reference', $reference))
            ->firstOrFail();
        $points = $trip->locations()->reorder('recorded_at', 'desc')->orderByDesc('id')
            ->limit(500)->get(['lat', 'lng'])->reverse()->values();

        return response()->json(['data' => $points->map(fn ($point) => [$point->lng, $point->lat])]);
    }

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
            $arrivedAt = now();
            $trip->update([$column => $arrivedAt]);
            Audit::log('arrival.'.$data['stage'], $cashRequest, ['at' => $arrivedAt->toIso8601String()],
                'mobile', (float) $data['latitude'], (float) $data['longitude'], $user->id);
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
