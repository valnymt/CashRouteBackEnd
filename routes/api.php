<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

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
    // Users & Teams (feature/users-teams-api)
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::put('/teams/{id}', [TeamController::class, 'update']);
});

// CashRoute endpoints go here when the backend is built. The frontend expects
// the routes listed in CashRoute_Admin_Portal/README.md → "Connecting the Laravel backend"
// (login, me, logout, branches, branch-directory, users, teams, requests, audit-logs,
// trips, notifications).
