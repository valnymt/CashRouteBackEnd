<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\CashRequest;
use App\Models\Trip;
use Illuminate\Http\Request;

/** Trip history + aggregate numbers used by the Reports page. */
class ReportController extends Controller
{
    /** GET /api/trips — Trip History: only finished runs with a real start→end to measure. */
    public function trips(Request $request)
    {
        $q = Trip::with(['cashRequest.branch', 'team'])
            ->whereNotNull('completed_at')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('team_id')) {
            $q->where('team_id', $request->team_id);
        }
        if ($request->filled('date_from')) {
            $q->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $q->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('branch_id')) {
            $q->whereHas('cashRequest', fn ($cr) => $cr->where('branch_id', $request->branch_id));
        }

        return TripResource::collection($q->get());
    }

    /** GET /api/trips/{reference} — detail by request reference. */
    public function showTrip(string $reference)
    {
        $trip = Trip::with(['cashRequest.branch', 'team', 'locations'])
            ->whereHas('cashRequest', fn ($cr) => $cr->where('reference', $reference))
            ->firstOrFail();

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
