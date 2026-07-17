<?php

declare(strict_types=1);

// TODO(D33): Versioning contract — additive changes are non-breaking;
// breaking changes require a new /api/v2/ prefix, coordinated across consumers.
// See docs/api-versioning.md for the full contract.

use App\Http\Controllers\Api\FrameworkController;
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

// ─── Framework Catalog API (C3) ──────────────────────────────────────────────
// Read-only endpoints serving the global BEAI framework catalog.
// Org-scoped via auth:api + TenantContext middleware (C2).
// FrameworkVersion is NOT required to exist — missing pin → 200 + pin_context: null.

Route::middleware('auth:api')->prefix('framework')->group(function (): void {
    Route::get('/roles', [FrameworkController::class, 'index']);
    Route::get('/roles/{roleCode}/competencies', [FrameworkController::class, 'roleCompetencies']);
    Route::get('/roles/{roleCode}/competencies/{competencyCode}/indicators', [FrameworkController::class, 'competencyBars']);
});
