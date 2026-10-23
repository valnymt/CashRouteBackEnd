<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashRequestResource;
use App\Models\Branch;
use App\Models\CashRequest;
use App\Models\Team;
use App\Models\Trip;
use App\Models\UserNotification;
use App\Support\Audit;
use App\Support\CompletionWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cash requests: list, show, create, edit, cancel, approve, reject, assign.
 * Business rules copied from src/services/mockApi.ts in the frontend.
 */
class CashRequestController extends Controller
{
    /** GET /api/requests — any signed-in user. */
    public function index()
    {
        return CashRequestResource::collection(
            CashRequest::with(['branch', 'creator', 'trip', 'auditLogs.user'])
                ->orderByDesc('id')
                ->get()
        );
    }

    /** GET /api/requests/{reference} */
    public function show(string $reference)
    {
        $r = $this->find($reference);

        return new CashRequestResource($r);
    }

    /** POST /api/requests — Makers only (Super Admin counts). */
    public function store(Request $request)
    {
        $me = $request->user();
        $this->ensureMaker($me);
        $data = $this->validated($request);

        $r = DB::transaction(function () use ($data, $me) {
            $r = CashRequest::create([
                'reference' => $this->nextReference(),
                'type' => strtolower($data['type']),
                'branch_id' => Branch::where('code', $data['branchId'])->value('id'),
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'MMK',
                'requested_date' => $data['date'],
                'complete_within' => CompletionWindow::toEnum($data['slot']),
                'due_at' => CompletionWindow::dueAt($data['date'], CompletionWindow::toEnum($data['slot'])),
                'remarks' => $data['remarks'] ?? null,
                'status' => 'pending',
                'created_by' => $me->id,
            ]);
            Audit::log('create', $r);

            return $r;
        });

        return (new CashRequestResource($r->fresh(['branch', 'creator', 'trip', 'auditLogs.user'])))
            ->response()->setStatusCode(201);
    }

    /** PUT /api/requests/{reference} — the Maker who created it, while Pending. */
    public function update(Request $request, string $reference)
    {
        $r = $this->find($reference);
        $me = $request->user();
        abort_unless($r->created_by === $me->id, 403, 'You can only edit your own request.');
        abort_if($r->status !== 'pending', 409, "{$r->reference} is {$r->status} and can no longer be edited.");

        $data = $this->validated($request);
        $enum = CompletionWindow::toEnum($data['slot']);

        $r->update([
            'type' => strtolower($data['type']),
            'branch_id' => Branch::where('code', $data['branchId'])->value('id'),
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? $r->currency,
            'requested_date' => $data['date'],
            'complete_within' => $enum,
            'due_at' => CompletionWindow::dueAt($data['date'], $enum),
            'remarks' => $data['remarks'] ?? null,
        ]);
        Audit::log('edit', $r);

        return new CashRequestResource($r->fresh(['branch', 'creator', 'trip', 'auditLogs.user']));
    }

    /**
     * POST /api/requests/{reference}/cancel
     * Maker: withdraw own Pending request (reason optional).
     * Checker: cancel Approved or Assigned request (reason required, frees the team).
     */
    public function cancel(Request $request, string $reference)
    {
        $r = $this->find($reference);
        $me = $request->user();
        $byChecker = in_array($r->status, ['approved', 'assigned'], true);

        if ($byChecker) {
            abort_unless($me->hasPermission('requests.cancel_approved'), 403, 'Only Checkers can cancel an approved request.');
            $reason = trim((string) $request->input('reason'));
            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Enter why this approved request is being cancelled.',
                ]);
            }
        } else {
            abort_unless($r->created_by === $me->id, 403, 'You can only cancel your own request.');
            abort_if($r->status !== 'pending', 409, "{$r->reference} is {$r->status} and can no longer be cancelled.");
            $reason = trim((string) $request->input('reason'));
        }

        DB::transaction(function () use ($r, $me, $byChecker, $reason) {
            // Checker cancel frees the assigned team (and ends the trip, if any).
            if ($byChecker && $r->trip) {
                Trip::where('id', $r->trip->id)->update(['status' => 'cancelled']);
                Team::where('id', $r->trip->team_id)->update(['status' => 'available']);
            }

            $r->update([
                'status' => 'cancelled',
                'cancelled_by' => $me->id,
                'cancelled_by_role' => $byChecker ? 'checker' : 'maker',
                'cancelled_at' => now(),
                'cancel_reason' => $reason ?: null,
            ]);

            Audit::log('cancel', $r, array_filter([
                'by' => $byChecker ? 'checker' : 'maker',
                'reason' => $reason ?: null,
            ]));
        });

        return new CashRequestResource($r->fresh(['branch', 'creator', 'trip', 'auditLogs.user']));
    }

    /** POST /api/requests/{reference}/approve — Checker, not own, Pending only. */
    public function approve(Request $request, string $reference)
    {
        $r = $this->find($reference);
        $me = $request->user();
        $this->ensureChecker($me);
        $this->ensureNotOwn($r, $me, 'approve');
        abort_if($r->status !== 'pending', 409, "{$r->reference} is already {$r->status}.");

        $r->update(['status' => 'approved', 'approved_by' => $me->id, 'approved_at' => now()]);
        Audit::log('approve', $r);

        return new CashRequestResource($r->fresh(['branch', 'creator', 'trip', 'auditLogs.user']));
    }

    /** POST /api/requests/{reference}/reject — reason required. */
    public function reject(Request $request, string $reference)
    {
        $r = $this->find($reference);
        $me = $request->user();
        $this->ensureChecker($me);
        $this->ensureNotOwn($r, $me, 'reject');
        abort_if($r->status !== 'pending', 409, "{$r->reference} is already {$r->status}.");

        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Required — submission is blocked until a reason is entered.',
            ]);
        }

        $r->update([
            'status' => 'rejected',
            'rejected_by' => $me->id,
            'rejected_at' => now(),
            'reject_reason' => $reason,
        ]);
        Audit::log('reject', $r, ['reason' => $reason]);

        return new CashRequestResource($r->fresh(['branch', 'creator', 'trip', 'auditLogs.user']));
    }

    /**
     * POST /api/requests/{reference}/assign  { team_id }
     * Checker, Approved only, team must be confirmed and available.
     */
    public function assign(Request $request, string $reference)
    {
        $r = $this->find($reference);
        $me = $request->user();
        abort_unless($me->hasPermission('requests.assign'), 403, 'You are not allowed to assign teams.');
        abort_if($r->status !== 'approved', 409, 'Only approved requests can be assigned.');

        $data = $request->validate([
            'team_id' => ['required', Rule::exists('teams', 'id')],
        ]);
        $team = Team::findOrFail($data['team_id']);
        abort_if($team->status !== 'available', 409, "{$team->name} is on a trip.");
        abort_unless($team->is_confirmed, 409, "{$team->name} isn't confirmed yet. Confirm it in Delivery Teams first.");

        DB::transaction(function () use ($r, $team, $me) {
            Trip::create([
                'cash_request_id' => $r->id,
                'team_id' => $team->id,
                'assigned_by' => $me->id,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);
            $r->update(['status' => 'assigned']);
            $team->update(['status' => 'on_trip']);

            Audit::log('assign', $r, ['team' => $team->name]);
            UserNotification::create([
                'user_id' => $team->members()->first()?->id ?? $me->id,
                'cash_request_id' => $r->id,
                'type' => 'team.assigned',
                'title' => "{$team->name} notified",
                'body' => "Push sent for {$r->reference}",
                'link' => "/approvals/{$r->reference}",
            ]);
        });

        return new CashRequestResource($r->fresh(['branch', 'creator', 'trip', 'auditLogs.user']));
    }

    /* ------------------------------------------------------------------ helpers */

    private function find(string $reference): CashRequest
    {
        return CashRequest::with(['branch', 'creator', 'trip', 'auditLogs.user'])
            ->where('reference', $reference)
            ->firstOrFail();
    }

    private function ensureMaker($user): void
    {
        abort_unless($user->hasPermission('requests.create'), 403, 'Only Makers can create requests.');
    }

    private function ensureChecker($user): void
    {
        abort_unless($user->hasPermission('requests.approve'), 403, 'Only Checkers can review requests.');
    }

    private function ensureNotOwn(CashRequest $r, $me, string $what): void
    {
        if ($r->created_by === $me->id) {
            abort(403, "Maker-Checker rule: you cannot {$what} a request you created.");
        }
    }

    /** Next REQ-#### (reference is unique in the DB). */
    private function nextReference(): string
    {
        $max = CashRequest::pluck('reference')
            ->map(fn ($r) => (int) preg_replace('/\D/', '', $r))
            ->max() ?? 1000;

        return 'REQ-' . ($max + 1);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['Deposit', 'Withdrawal'])],
            'branchId' => ['required', Rule::exists('branches', 'code')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'date' => ['required', 'date_format:Y-m-d'],
            'slot' => ['required', Rule::in(CompletionWindow::labels())],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
