<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRequest;
use App\Models\Team;
use App\Support\Audit;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function approve(string $id)
    {
        $cashRequest = CashRequest::find($id);

        if (!$cashRequest) {
            return response()->json(['success' => false, 'message' => 'Request not found'], 404);
        }

        if ($cashRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only pending requests can be approved'], 422);
        }

        if ($cashRequest->created_by === auth()->id()) {
            return response()->json(['success' => false, 'message' => 'You cannot approve your own request (Maker-Checker rule)'], 403);
        }

        $cashRequest->update([
            'status'      => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        Audit::log('approve', $cashRequest);

        return response()->json(['success' => true, 'data' => $cashRequest]);
    }

    public function reject(Request $request, string $id)
    {
        $request->validate(['reason' => 'required|string']);

        $cashRequest = CashRequest::find($id);

        if (!$cashRequest) {
            return response()->json(['success' => false, 'message' => 'Request not found'], 404);
        }

        if ($cashRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only pending requests can be rejected'], 422);
        }

        if ($cashRequest->created_by === auth()->id()) {
            return response()->json(['success' => false, 'message' => 'You cannot reject your own request (Maker-Checker rule)'], 403);
        }

        $cashRequest->update([
            'status'      => 'rejected',
            'reason'      => $request->reason,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        Audit::log('reject', $cashRequest, ['reason' => $request->reason]);

        return response()->json(['success' => true, 'message' => 'Request rejected']);
    }

    public function assign(Request $request, string $id)
    {
        $request->validate(['team_id' => 'required|exists:teams,id']);

        $cashRequest = CashRequest::find($id);

        if (!$cashRequest) {
            return response()->json(['success' => false, 'message' => 'Request not found'], 404);
        }

        if ($cashRequest->status !== 'approved') {
            return response()->json(['success' => false, 'message' => 'Only approved requests can be assigned'], 422);
        }

        $team = Team::find($request->team_id);

        if (!$team->is_available) {
            return response()->json(['success' => false, 'message' => 'This team is not available'], 422);
        }

        $cashRequest->update(['status' => 'assigned']);

        $cashRequest->trip()->create([
            'team_id' => $team->id,
            'status'  => 'assigned',
        ]);

        $team->update(['is_available' => false]);

        Audit::log('assign', $cashRequest, ['team_id' => $team->id, 'team_name' => $team->name]);

        return response()->json(['success' => true, 'data' => $cashRequest]);
    }

    public function cancelApproved(Request $request, string $id)
    {
        $request->validate(['reason' => 'nullable|string']);

        $cashRequest = CashRequest::find($id);

        if (!$cashRequest) {
            return response()->json(['success' => false, 'message' => 'Request not found'], 404);
        }

        if (!in_array($cashRequest->status, ['approved', 'assigned'])) {
            return response()->json(['success' => false, 'message' => 'Only approved or assigned requests can be cancelled by a checker'], 422);
        }

        $cashRequest->update([
            'status'       => 'cancelled',
            'reason'       => $request->reason,
            'cancelled_by' => auth()->id(),
        ]);

        Audit::log('cancel', $cashRequest, ['reason' => $request->reason, 'cancelled_by' => 'checker']);

        return response()->json(['success' => true, 'message' => 'Request cancelled']);
    }
}
