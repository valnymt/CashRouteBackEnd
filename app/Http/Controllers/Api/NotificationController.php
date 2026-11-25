<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserNotificationResource;
use App\Http\Resources\MobileAssignmentResource;
use App\Models\Branch;
use App\Models\UserNotification;
use App\Support\CompletionWindow;
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
                ->orderByDesc('id')
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

    public function show(Request $request, string $id)
    {
        $notice = $this->ownedNotification($request, $id);
        $cashRequest = $notice->cashRequest;
        $assignment = null;

        if ($cashRequest && in_array($notice->type, ['team.assigned', 'team.reassigned', 'team.accepted'], true)
            && in_array($cashRequest->status, ['assigned', 'reassigned', 'in_transit'], true)
            && $cashRequest->trip?->team?->members()->whereKey($request->user()->id)->exists()
        ) {
            $hq = Branch::where('is_hq', true)->first();
            if ($hq) {
                $cashRequest->setRelation('hqBranch', $hq);
                $assignment = (new MobileAssignmentResource($cashRequest))->resolve($request);
                $assignment['time_slot'] = $cashRequest->complete_within
                    ? CompletionWindow::toLabel($cashRequest->complete_within)
                    : null;
            }
        }

        return (new UserNotificationResource($notice))->additional([
            'reference' => $cashRequest?->reference,
            'assignment' => $assignment,
            'cancelReason' => $notice->type === 'request.cancelled' ? $cashRequest?->cancel_reason : null,
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notice = $this->ownedNotification($request, $id);
        if ($notice->read_at === null) {
            $notice->update(['read_at' => now()]);
        }

        return response()->noContent();
    }

    private function ownedNotification(Request $request, string $id): UserNotification
    {
        $notificationId = preg_replace('/^N-/', '', $id);
        abort_unless(ctype_digit($notificationId), 404, 'Notification not found.');

        return UserNotification::where('user_id', $request->user()->id)->findOrFail($notificationId);
    }
}
