<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRequest;
use App\Models\Trip;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function trips(Request $request)
    {
        $query = Trip::with(['cashRequest.branch', 'team'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('team_id')) {
            $query->where('team_id', $request->team_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('branch_id')) {
            $query->whereHas('cashRequest', fn($q) => $q->where('branch_id', $request->branch_id));
        }

        return response()->json([
            'success' => true,
            'data'    => $query->paginate(20),
        ]);
    }

    public function showTrip(string $id)
    {
        $trip = Trip::with(['cashRequest.branch', 'team', 'locations'])->find($id);

        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $trip]);
    }

    public function summary(Request $request)
    {
        $query = CashRequest::query();

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $total      = (clone $query)->count();
        $pending    = (clone $query)->where('status', 'pending')->count();
        $approved   = (clone $query)->where('status', 'approved')->count();
        $rejected   = (clone $query)->where('status', 'rejected')->count();
        $assigned   = (clone $query)->where('status', 'assigned')->count();
        $inTransit  = (clone $query)->where('status', 'in_transit')->count();
        $delivered  = (clone $query)->where('status', 'delivered')->count();
        $cancelled  = (clone $query)->where('status', 'cancelled')->count();
        $totalAmount = (clone $query)->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'total'        => $total,
                'total_amount' => $totalAmount,
                'by_status'    => compact('pending', 'approved', 'rejected', 'assigned', 'inTransit', 'delivered', 'cancelled'),
            ],
        ]);
    }
}
