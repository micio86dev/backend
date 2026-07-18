<?php

declare(strict_types=1);

/**
 * Guard wiring unit tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - Auth::guard('api-m2m') resolves without InvalidArgumentException
 * - The api-m2m guard is NOT the same instance as the api (JWT) guard
 * - Guard returns null (not an exception) when no Bearer header is present
 *
 * REQ-3 / design §Guard
 */

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;

test('auth:api-m2m guard resolves without InvalidArgumentException', function (): void {
    // This would throw InvalidArgumentException if config/auth.php entry or
    // Auth::viaRequest registration is missing.
    $guard = Auth::guard('api-m2m');

    expect($guard)->not->toBeNull();
});

test('api-m2m guard is a RequestGuard', function (): void {
    $guard = Auth::guard('api-m2m');

    expect($guard)->toBeInstanceOf(RequestGuard::class);
});

test('api-m2m guard is NOT the same instance as the api (JWT) guard', function (): void {
    $m2mGuard = Auth::guard('api-m2m');
    $apiGuard = Auth::guard('api');

    expect($m2mGuard)->not->toBe($apiGuard);
});

test('api-m2m guard returns null when no Authorization header is present', function (): void {
    $guard = Auth::guard('api-m2m');

    // No Bearer header → closure returns null → user() is null
    expect($guard->user())->toBeNull();
});
