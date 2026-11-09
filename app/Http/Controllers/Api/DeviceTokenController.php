<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * POST /api/device-tokens
     * Register or refresh a device's push token.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:android,ios,web'],
        ]);

        $user = $request->user();

        // A token belongs to one account/device registration.
        // If this device token already exists, transfer it to the
        // currently authenticated user.
        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Device token registered successfully.',
            'device' => [
                'id' => $device->id,
                'platform' => $device->platform,
            ],
        ], 201);
    }

    /**
     * DELETE /api/device-tokens/{id}
     * Remove only the signed-in user's device token.
     */
    public function destroy(Request $request, string $id)
    {
        $device = DeviceToken::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $device->delete();

        return response()->noContent();
    }
}