<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRequest;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class HandoverProofController extends Controller
{
    public function storeSinglePhoto(Request $request, string $reference)
    {
        $request->files->set('photos', [$request->files->get('photo')]);

        return $this->store($request, $reference);
    }

    public function store(Request $request, string $reference)
    {
        $user = $request->user();
        abort_unless($user->is_delivery_member && $user->hasPermission('handover.perform'), 403);

        $data = $request->validate([
            'confirmed_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'receiver_name' => ['nullable', 'string', 'max:120'],
            'photos' => ['required', 'array', 'min:1', 'max:3'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'signature' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'signature_png' => ['nullable', 'string', 'max:524288', 'prohibits:signature'],
        ]);

        $signaturePng = null;
        if (isset($data['signature_png'])) {
            $signaturePng = base64_decode($data['signature_png'], true);
            $size = $signaturePng ? @getimagesizefromstring($signaturePng) : false;
            if (!$size || $size[2] !== IMAGETYPE_PNG || $size[0] > 2048 || $size[1] > 2048) {
                throw ValidationException::withMessages(['signature_png' => 'The receiver signature must be a valid PNG.']);
            }
        }

        $paths = [];
        $storeImage = function ($image) use (&$paths) {
            $path = $image->store('handover-proof', 'local');
            if ($path === false) {
                throw new RuntimeException('Could not store the handover image.');
            }
            $paths[] = $path;
            return $path;
        };

        try {
            [$proof, $created] = DB::transaction(function () use ($data, $reference, $user, $storeImage, $signaturePng, &$paths) {
                $cashRequest = CashRequest::where('reference', $reference)->lockForUpdate()->firstOrFail();
                $trip = $cashRequest->trip()->lockForUpdate()->firstOrFail();
                abort_unless($trip->accepted_at && $user->teams()->whereKey($trip->team_id)->exists(), 403);

                $existing = $cashRequest->handoverProofs()->where('stage', 'dropoff')->latest('id')->first();
                if ($existing && $trip->status === 'completed' && $cashRequest->status === 'completed') {
                    $trip->team->releaseIfIdle();

                    return [$existing, false];
                }

                $verified = $cashRequest->otps()->where('stage', 'dropoff')
                    ->where('status', 'verified')->where('verified_at', '>=', $trip->accepted_at)->exists();
                abort_unless($trip->status === 'in_transit' && $cashRequest->status === 'in_transit' && $verified,
                    409, 'An in-transit trip with verified drop-off OTP is required.');

                if (!BigDecimal::of($data['confirmed_amount'])->isEqualTo($cashRequest->amount)) {
                    throw ValidationException::withMessages([
                        'confirmed_amount' => 'The confirmed amount must match the cash request.',
                    ]);
                }

                $signaturePath = isset($data['signature']) ? $storeImage($data['signature']) : null;
                if ($signaturePng !== null) {
                    $signaturePath = 'handover-proof/'.Str::uuid().'.png';
                    $paths[] = $signaturePath;
                    if (!Storage::disk('local')->put($signaturePath, $signaturePng)) {
                        throw new RuntimeException('Could not store the receiver signature.');
                    }
                }

                $completedAt = now();
                $proof = $cashRequest->handoverProofs()->create([
                    'stage' => 'dropoff',
                    'confirmed_amount' => $cashRequest->amount,
                    'denominations' => $cashRequest->denominations,
                    'receiver_name' => $data['receiver_name'] ?? null,
                    'signature_path' => $signaturePath,
                    'submitted_by' => $user->id,
                    'submitted_at' => $completedAt,
                ]);

                foreach ($data['photos'] as $photo) {
                    $proof->photos()->create(['path' => $storeImage($photo)]);
                }

                $trip->forceFill([
                    'status' => 'completed',
                    'completed_at' => $completedAt,
                    'is_on_time' => $cashRequest->due_at ? $completedAt->lte($cashRequest->due_at) : null,
                ])->save();
                $cashRequest->update(['status' => 'completed', 'completed_at' => $completedAt]);

                $trip->team->releaseIfIdle();

                return [$proof, true];
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($paths);
            throw $exception;
        }

        return response()->json([
            'id' => $proof->id,
            'reference' => $reference,
            'status' => 'completed',
            'submitted_at' => $proof->submitted_at->toIso8601String(),
        ], $created ? 201 : 200);
    }

    public function image(Request $request, string $reference, string $image)
    {
        $cashRequest = CashRequest::where('reference', $reference)->firstOrFail();
        abort_unless($cashRequest->trip?->canViewHandoverProof($request->user()), 403);
        $proof = $cashRequest->handoverProofs()->where('stage', 'dropoff')->latest('id')->firstOrFail();
        $path = $image === 'signature' ? $proof->signature_path : $proof->photos()->findOrFail($image)->path;
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
