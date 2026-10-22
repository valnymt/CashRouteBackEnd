<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

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
    // every other module's routes go inside this group too
});

// CashRoute endpoints go here when the backend is built. The frontend expects
// the routes listed in CashRoute_Admin_Portal/README.md → "Connecting the Laravel backend"
// (login, me, logout, branches, branch-directory, users, teams, requests, audit-logs,
// trips, notifications).
