<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashRequestController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\UserController;

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
    Route::post('/logout', [AuthController::class, 'logout']);

    // Users & Teams
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::put('/teams/{id}', [TeamController::class, 'update']);

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

    // Reports & Trips
    Route::get('/trips', [ReportController::class, 'trips']);
    Route::get('/trips/{reference}', [ReportController::class, 'showTrip']);
    Route::get('/reports/summary', [ReportController::class, 'summary']);

    // OTP (mobile app — pickup/dropoff handover)
    Route::post('/cash-requests/{cashRequest}/otp', [OtpController::class, 'generate']);
    Route::post('/otps/verify', [OtpController::class, 'verify']);
});
