<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Quick check that the API is up: GET /api/health
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// CashRoute endpoints go here when the backend is built. The frontend expects
// the routes listed in CashRoute_Admin_Portal/README.md → "Connecting the Laravel backend"
// (login, me, logout, branches, branch-directory, users, teams, requests, audit-logs,
// trips, notifications).
