<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\CashRequest;
use App\Models\Trip;
use App\Models\Branch;
use Illuminate\Http\Request;

/** Trip history + aggregate numbers used by the Reports page. */
class ReportController extends Controller
{
    /** GET /api/trips — Trip History: only finished runs with a real start→end to measure. */
    // public function trips(Request $request)
    // {
    //     $request->attributes->set(
    //         'hqBranch',
    //         Branch::where('is_hq', true)->firstOrFail()
    //     );
    //     $q = Trip::with(['cashRequest.branch', 'team'])
    //         ->whereNotNull('completed_at')
    //         ->orderByDesc('created_at');

    //     if ($request->filled('status')) {
    //         $q->where('status', $request->status);
    //     }
    //     if ($request->filled('team_id')) {
    //         $q->where('team_id', $request->team_id);
    //     }
    //     if ($request->filled('date_from')) {
    //         $q->whereDate('created_at', '>=', $request->date_from);
    //     }
    //     if ($request->filled('date_to')) {
    //         $q->whereDate('created_at', '<=', $request->date_to);
    //     }
    //     if ($request->filled('branch_id')) {
    //         $q->whereHas('cashRequest', fn ($cr) => $cr->where('branch_id', $request->branch_id));
    //     }

    //     return TripResource::collection($q->get());
    // }

/** GET /api/trips — Trip History */
public function trips(Request $request)
{
    $request->attributes->set(
        'hqBranch',
        Branch::where('is_hq', true)->firstOrFail()
    );

    $user = $request->user();

    $q = Trip::with(['cashRequest.branch', 'team'])
        ->orderByDesc('created_at');

    if ($user->is_delivery_member) {
        $team = $user->teams()->first();

        if (!$team) {
            return TripResource::collection(collect());
        }

        $q->where('team_id', $team->id);

        if ($request->boolean('include_cancelled')) {
            $q->where(function ($query) {
                $query->whereNotNull('completed_at')
                    ->orWhere('status', 'cancelled');
            });
        } else {
            $q->whereNotNull('completed_at');
        }
    } else {
        // Keep existing Admin Portal behavior.
        $q->whereNotNull('completed_at');
    }

    if ($request->filled('status')) {
        $q->where('status', $request->status);
    }

    if (!$user->is_delivery_member && $request->filled('team_id')) {
        $q->where('team_id', $request->team_id);
    }

    if ($request->filled('date_from')) {
        $q->whereDate('created_at', '>=', $request->date_from);
    }

    if ($request->filled('date_to')) {
        $q->whereDate('created_at', '<=', $request->date_to);
    }

    if ($request->filled('branch_id')) {
        $q->whereHas('cashRequest', fn ($cr) =>
            $cr->where('branch_id', $request->branch_id)
        );
    }

    return TripResource::collection($q->get());
}

/** GET /api/trips/live — active trips for Admin Portal live monitoring. */
public function liveTrips(Request $request)
{
    // This endpoint is for the Admin Portal, not delivery-team mobile users.
    if ($request->user()->is_delivery_member) {
        abort(403, 'This endpoint is for Admin Portal users.');
    }

    $request->attributes->set(
        'hqBranch',
        Branch::where('is_hq', true)->firstOrFail()
    );

    $trips = Trip::with(['cashRequest.branch', 'team'])
        ->whereIn('status', ['assigned', 'in_transit'])
        ->whereNotNull('current_lat')
        ->whereNotNull('current_lng')
        ->orderByDesc('last_ping_at')
        ->get();

    return TripResource::collection($trips);
}

    /** GET /api/trips/{reference} — detail by request reference. */
    public function showTrip(string $reference)
    {
        request()->attributes->set(
            'hqBranch',
            Branch::where('is_hq', true)->firstOrFail()
        );

        $trip = Trip::with(['cashRequest.branch', 'team', 'locations'])
            ->whereHas('cashRequest', fn ($cr) => $cr->where('reference', $reference))
            ->firstOrFail();

        if (request()->user()->hasPermission('reports.view')) {
            $trip->cashRequest->load(['handoverProofs' => fn ($query) => $query
                ->where('stage', 'dropoff')->latest('id')->limit(1)->with('photos')]);
        }

        return new TripResource($trip);
    }

    /** GET /api/reports/summary — totals and status breakdown. */
    public function summary(Request $request)
    {
        $q = CashRequest::query();

        if ($request->filled('date_from')) {
            $q->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $q->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('branch_id')) {
            $q->where('branch_id', $request->branch_id);
        }
        if ($request->filled('type')) {
            $q->where('type', $request->type);
        }

        return [
            'total'       => (clone $q)->count(),
            'totalAmount' => (float) (clone $q)->sum('amount'),
            'byStatus' => [
                'pending'   => (clone $q)->where('status', 'pending')->count(),
                'approved'  => (clone $q)->where('status', 'approved')->count(),
                'rejected'  => (clone $q)->where('status', 'rejected')->count(),
                'assigned'  => (clone $q)->where('status', 'assigned')->count(),
                'inTransit' => (clone $q)->where('status', 'in_transit')->count(),
                'completed' => (clone $q)->where('status', 'completed')->count(),
                'cancelled' => (clone $q)->where('status', 'cancelled')->count(),
            ],
        ];
    }
}
