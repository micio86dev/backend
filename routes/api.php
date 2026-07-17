<?php

declare(strict_types=1);

// TODO(D33): Versioning contract — additive changes are non-breaking;
// breaking changes require a new /api/v2/ prefix, coordinated across consumers.
// See docs/api-versioning.md for the full contract.

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// ─── Auth routes (C2) ────────────────────────────────────────────────────────
// POST /api/auth/login is public (no auth middleware).
// All other auth routes use auth:api explicitly — NEVER bare `auth` middleware,
// which would silently fall back to the `web` session guard.

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function (): void {
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});
