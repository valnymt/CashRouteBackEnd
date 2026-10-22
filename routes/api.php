<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashRequestController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\UserController;

// Quick check that the API is up: GET /api/health
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

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

    // Cash Requests
    Route::get('/requests', [CashRequestController::class, 'index']);
    Route::post('/requests', [CashRequestController::class, 'store']);
    Route::get('/requests/{id}', [CashRequestController::class, 'show']);
    Route::put('/requests/{id}', [CashRequestController::class, 'update']);
    Route::post('/requests/{id}/cancel', [CashRequestController::class, 'cancel']);

    // Approvals
    Route::post('/requests/{id}/approve', [ApprovalController::class, 'approve']);
    Route::post('/requests/{id}/reject',  [ApprovalController::class, 'reject']);
    Route::post('/requests/{id}/assign',  [ApprovalController::class, 'assign']);
    Route::post('/requests/{id}/cancel-approved', [ApprovalController::class, 'cancelApproved']);
});
