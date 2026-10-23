<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserNotificationResource;
use App\Models\UserNotification;
use Illuminate\Http\Request;

/** In-app notifications bell: list and mark-all-read for the signed-in user. */
class NotificationController extends Controller
{
    /** GET /api/notifications */
    public function index(Request $request)
    {
        return UserNotificationResource::collection(
            UserNotification::where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
        );
    }

    /** POST /api/notifications/read-all */
    public function readAll(Request $request)
    {
        UserNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->noContent();
    }
}
