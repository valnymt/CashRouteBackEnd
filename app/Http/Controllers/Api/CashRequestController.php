<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRequest;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CashRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = CashRequest::with(['branch', 'creator'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->paginate(20),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'            => 'required|in:deposit,withdrawal',
            'branch_id'       => 'required|exists:branches,id',
            'amount'          => 'required|numeric|min:0.01',
            'currency'        => 'nullable|string|size:3',
            'requested_date'  => 'required|date',
            'complete_within' => 'required|in:1h,3h,5h,10h,12h,1d,2d',
            'remarks'         => 'nullable|string',
        ]);

        $validated['reference']  = 'REQ-' . strtoupper(Str::random(6));
        $validated['status']     = 'pending';
        $validated['created_by'] = auth()->id();
        $validated['currency']   = $validated['currency'] ?? 'MMK';

        $cashRequest = CashRequest::create($validated);

        Audit::log('create', $cashRequest);

        return response()->json(['success' => true, 'data' => $cashRequest], 201);
    }

    public function show(string $id)
    {
        $cashRequest = CashRequest::with(['branch', 'creator', 'approver', 'auditLogs'])
            ->find($id);

        if (!$cashRequest) {
            return response()->json(['success' => false, 'message' => 'Request not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $cashRequest]);
    }

    public function update(Request $request, string $id)
    {
        $cashRequest = CashRequest::find($id);

        if (!$cashRequest) {
            return response()->json(['success' => false, 'message' => 'Request not found'], 404);
        }

        if ($cashRequest->created_by !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Only the maker who created this request can edit it'], 403);
        }

        if ($cashRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only pending requests can be edited'], 422);
        }

        $validated = $request->validate([
            'type'            => 'sometimes|in:deposit,withdrawal',
            'branch_id'       => 'sometimes|exists:branches,id',
            'amount'          => 'sometimes|numeric|min:0.01',
            'currency'        => 'nullable|string|size:3',
            'requested_date'  => 'sometimes|date',
            'complete_within' => 'sometimes|in:1h,3h,5h,10h,12h,1d,2d',
            'remarks'         => 'nullable|string',
        ]);

        $cashRequest->update($validated);

        Audit::log('edit', $cashRequest);

        return response()->json(['success' => true, 'data' => $cashRequest]);
    }

    public function cancel(Request $request, string $id)
    {
        $cashRequest = CashRequest::find($id);

        if (!$cashRequest) {
            return response()->json(['success' => false, 'message' => 'Request not found'], 404);
        }

        if ($cashRequest->created_by !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Only the maker who created this request can cancel it'], 403);
        }

        if ($cashRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only pending requests can be cancelled'], 422);
        }

        $request->validate(['reason' => 'nullable|string']);

        $cashRequest->update([
            'status'       => 'cancelled',
            'reason'       => $request->reason,
            'cancelled_by' => auth()->id(),
        ]);

        Audit::log('cancel', $cashRequest, ['reason' => $request->reason]);

        return response()->json(['success' => true, 'message' => 'Request cancelled']);
    }
}
