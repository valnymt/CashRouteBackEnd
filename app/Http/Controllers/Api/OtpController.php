<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\HandoverOtpMail;
use App\Models\Branch;
use App\Models\CashRequest;
use App\Models\Otp;
use App\Models\OtpAttempt;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpController extends Controller
{
    /** Return the authorized handover contact before sending a code. */
    public function contact(Request $request, CashRequest $cashRequest)
    {
        $stage = $request->validate(['stage' => 'required|in:pickup,dropoff'])['stage'];
        $this->authorizeHandover($request, $cashRequest);

        return response()->json($this->handoverContact($cashRequest, $stage));
    }

    private function authorizeHandover(Request $request, CashRequest $cashRequest): void
    {
        $trip = $cashRequest->trip;
        abort_unless($trip?->accepted_at && $trip->hasDeliveryMember($request->user()), 403,
            'You must belong to the accepted delivery team to view or request this OTP.');
    }

    private function handoverContact(CashRequest $cashRequest, string $stage): array
    {
        $atBranch = ($cashRequest->type === 'deposit') === ($stage === 'pickup');
        $location = $atBranch ? $cashRequest->branch : Branch::where('is_hq', true)->first();
        $testEmail = app()->environment('local', 'testing') ? config('mail.handover_otp_test_recipient') : null;

        return [
            'name' => $location?->contact_name,
            'phone' => $location?->contact_phone,
            'email' => $testEmail ?: $location?->contact_email,
            'is_test_recipient' => (bool) $testEmail,
        ];
    }

    /** Generate a pickup or drop-off OTP and email it to the handover recipient. */
    public function generate(Request $request, CashRequest $cashRequest)
    {
        $validated = $request->validate([
            'stage' => 'required|in:pickup,dropoff',
        ]);

        $user = $request->user();
        $this->authorizeHandover($request, $cashRequest);
        $contact = $this->handoverContact($cashRequest, $validated['stage']);
        $email = $contact['email'];

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'The branch recipient email is missing or invalid.',
            ], 422);
        }

        $plainCode = (string) random_int(100000, 999999);

        $otp = DB::transaction(function () use (
            $cashRequest,
            $user,
            $validated,
            $plainCode,
            $email
        ) {
            // Expire any previous pending OTP for this request and stage.
            Otp::where('cash_request_id', $cashRequest->id)
                ->where('stage', $validated['stage'])
                ->where('status', 'pending')
                ->update(['status' => 'expired']);

            return Otp::create([
                'cash_request_id' => $cashRequest->id,
                'requested_by' => $user->id,
                'stage' => $validated['stage'],
                'code_hash' => Hash::make($plainCode),
                'sent_to' => $email,
                'channel' => 'email',
                'expires_at' => now()->addMinutes(5),
                'attempts' => 0,
                'status' => 'pending',
            ]);
        });

        try {
            Mail::to($email)->send(
                new HandoverOtpMail(
                    $plainCode,
                    $validated['stage'],
                    5
                )
            );
        } catch (\Throwable $e) {
            $otp->update(['status' => 'failed']);

            Log::error('CashRoute OTP email delivery failed.', [
                'otp_id' => $otp->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to send the handover email.',
            ], 500);
        }

        return response()->json([
            'message' => 'Handover OTP generated.',
            'otp_id' => $otp->id,
            'stage' => $otp->stage,
            'expires_at' => $otp->expires_at,
            'contact' => $contact,
        ], 201);
    }

    /**
     * Verify a pickup or dropoff OTP.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'otp_id' => 'required|integer|exists:otps,id',
            'code' => 'required|string|size:6',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $result = DB::transaction(function () use ($validated, $user) {
            $otp = Otp::where('id', $validated['otp_id'])
                ->lockForUpdate()
                ->first();

            if (!$otp) {
                return [
                    'error' => 'OTP not found.',
                    'http' => 404,
                ];
            }

            $trip = $otp->cashRequest?->trip;
            $isTeamMember = $trip
                ? DB::table('team_members')
                    ->where('team_id', $trip->team_id)
                    ->where('user_id', $user->id)
                    ->exists()
                : false;

            if (!$user->is_delivery_member || !$trip || !$isTeamMember || $trip->accepted_at === null) {
                return [
                    'error' => 'You are not authorized to verify this OTP.',
                    'http' => 403,
                ];
            }
            if ($otp->stage === 'pickup' && $trip->status !== 'assigned') {
                return [
                    'error' => 'Pickup verification is only allowed for an assigned trip.',
                    'http' => 422,
                ];
            }

            if ($otp->status !== 'pending') {
                return [
                    'error' => 'This OTP is no longer valid.',
                    'http' => 422,
                ];
            }

            if ($otp->expires_at->isPast()) {
                $otp->update(['status' => 'expired']);

                return [
                    'error' => 'This OTP has expired.',
                    'http' => 422,
                ];
            }

            if ($otp->attempts >= 3) {
                $otp->update(['status' => 'failed']);

                return [
                    'error' => 'Maximum OTP attempts reached.',
                    'http' => 422,
                ];
            }

            $success = Hash::check(
                $validated['code'],
                $otp->code_hash
            );

            $otp->increment('attempts');

            if (!$success && $otp->attempts >= 3) {
                $otp->update(['status' => 'failed']);
            }

            OtpAttempt::create([
                'otp_id' => $otp->id,
                'user_id' => $user->id,
                'is_success' => $success,
                'lat' => $validated['lat'] ?? null,
                'lng' => $validated['lng'] ?? null,
                'attempted_at' => now(),
            ]);

            if (!$success) {
                return [
                    'error' => $otp->status === 'failed'
                        ? 'Maximum OTP attempts reached.'
                        : 'Incorrect OTP.',
                    'http' => 422,
                ];
            }

            $verifiedAt = now();

            $otp->update([
                'status' => 'verified',
                'verified_at' => $verifiedAt,
            ]);

            if ($otp->stage === 'pickup') {
                $trip->update([
                    'status' => 'in_transit',
                    'started_at' => $trip->started_at ?? $verifiedAt,
                ]);
                 // Use the trip's cash_request_id relationship, not the trip's own id.
                $trip->cashRequest()->update([
                    'status' => 'in_transit',
                ]);
            }

            return [
                'success' => true,
                'stage' => $otp->stage,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], $result['http']);
        }

        return response()->json([
            'message' => 'OTP verified successfully.',
            'stage' => $result['stage'],
        ]);
    }
}
