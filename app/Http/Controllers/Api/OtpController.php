<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\HandoverOtpMail;
use App\Models\CashRequest;
use App\Models\Otp;
use App\Models\OtpAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpController extends Controller
{
    /**
     * Generate a pickup or dropoff OTP and email it
     * to the branch contact.
     */
    public function generate(Request $request, CashRequest $cashRequest)
    {
        $validated = $request->validate([
            'stage' => 'required|in:pickup,dropoff',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // TODO: Authorize the user for this cash request and stage.
        $cashRequest->load('branch');

        $email = $cashRequest->branch?->contact_email;

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

            $otp->update([
                'status' => 'verified',
                'verified_at' => now(),
            ]);

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
