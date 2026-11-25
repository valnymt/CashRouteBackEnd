<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashRequestController;
use App\Http\Controllers\Api\HandoverProofController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DeviceTokenController;

// Quick check that the API is up: GET /api/health
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app'    => config('app.name'),
    'time'   => now()->toIso8601String(),
]));

// Auth (unauthenticated)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/token', [AuthController::class, 'token']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/password', [AuthController::class, 'changePassword']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Users & Teams
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    // SA only — unlocks the account, generates a one-time password, returns it in
    // the response so the admin can read it to the user (and we log it too).
    Route::post('/users/{id}/reactivate', [UserController::class, 'reactivate']);
    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::put('/teams/{id}', [TeamController::class, 'update']);
    Route::put('/teams/{id}/leader', [TeamController::class, 'setLeader']);

    // Branches
    Route::get('/branch-directory', [BranchController::class, 'directory']);
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::get('/branches/{code}', [BranchController::class, 'show']);
    Route::put('/branches/{code}', [BranchController::class, 'update']);
    Route::delete('/branches/{code}', [BranchController::class, 'destroy']);

    // Cash Requests (list, create, edit, cancel, approve, reject, assign)
    Route::get('/requests', [CashRequestController::class, 'index']);
    Route::post('/requests', [CashRequestController::class, 'store']);
    Route::get('/requests/{reference}', [CashRequestController::class, 'show']);
    Route::put('/requests/{reference}', [CashRequestController::class, 'update']);
    Route::post('/requests/{reference}/cancel',  [CashRequestController::class, 'cancel']);
    Route::post('/requests/{reference}/approve', [CashRequestController::class, 'approve']);
    Route::post('/requests/{reference}/reject',  [CashRequestController::class, 'reject']);
    Route::post('/requests/{reference}/assign',  [CashRequestController::class, 'assign']);
    //for mobil only 
    Route::post('/requests/{reference}/accept', [CashRequestController::class, 'accept']);
    Route::get('/my-assignments', [CashRequestController::class,'myAssignments', ]);

    // Reports & Trips
    Route::get('/trips', [ReportController::class, 'trips']);
    Route::get('/trips/live', [ReportController::class, 'liveTrips']);
    Route::get('/trips/{reference}', [ReportController::class, 'showTrip']);
    Route::get('/trips/{reference}/track', [TripController::class, 'track']);
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::post('/trips/{reference}/arrival', [TripController::class, 'arrive']);
    Route::post('/trips/{reference}/location',[TripController::class, 'updateLocation']);
    Route::post('/trips/{reference}/proof', [HandoverProofController::class, 'storeSinglePhoto']);

    // OTP (mobile app — pickup/dropoff handover)
    Route::get('/cash-requests/{cashRequest}/otp/contact', [OtpController::class, 'contact']);
    Route::post('/cash-requests/{cashRequest}/otp', [OtpController::class, 'generate']);
    Route::post('/otps/verify', [OtpController::class, 'verify']);
    Route::post('/requests/{reference}/proof-of-delivery', [HandoverProofController::class, 'store']);
    Route::get('/trips/{reference}/proof-of-delivery/{image}', [HandoverProofController::class, 'image'])
        ->where('image', 'signature|[0-9]+');

    // Audit Trail (AP-09)
    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // Notifications bell
    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/{id}', [NotificationController::class, 'show']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

    // Mobile device push tokens
    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens/current', [DeviceTokenController::class, 'destroyCurrent']);
    Route::delete('/device-tokens/{id}', [DeviceTokenController::class, 'destroy']);
});
