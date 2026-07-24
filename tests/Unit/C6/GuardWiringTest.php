<?php

declare(strict_types=1);

/**
 * api-candidate guard wiring unit tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - 'api-candidate' entry exists in config/auth.php with no 'provider' key
 * - Auth::viaRequest registered under 'api-candidate' (resolves as RequestGuard)
 * - Guard returns null (not exception) when no Bearer header is present
 * - Guard is NOT the same instance as 'api' or 'api-m2m' guards
 *
 * REQ: api-candidate Guard
 */

use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;

test('api-candidate entry exists in config/auth.php', function (): void {
    $guards = config('auth.guards');

    expect($guards)->toHaveKey('api-candidate');
});

test('api-candidate config entry has driver key', function (): void {
    $entry = config('auth.guards.api-candidate');

    expect($entry)->toHaveKey('driver');
    expect($entry['driver'])->toBe('api-candidate');
});

test('api-candidate config entry has NO provider key (security invariant)', function (): void {
    $entry = config('auth.guards.api-candidate');

    expect($entry)->not->toHaveKey('provider');
});

test('api-candidate guard resolves without InvalidArgumentException', function (): void {
    $guard = Auth::guard('api-candidate');

    expect($guard)->not->toBeNull();
});

test('api-candidate guard is a RequestGuard', function (): void {
    $guard = Auth::guard('api-candidate');

    expect($guard)->toBeInstanceOf(RequestGuard::class);
});

test('api-candidate guard is NOT the same instance as the api (JWT) guard', function (): void {
    $candidateGuard = Auth::guard('api-candidate');
    $apiGuard = Auth::guard('api');

    expect($candidateGuard)->not->toBe($apiGuard);
});

test('api-candidate guard is NOT the same instance as the api-m2m guard', function (): void {
    $candidateGuard = Auth::guard('api-candidate');
    $m2mGuard = Auth::guard('api-m2m');

    expect($candidateGuard)->not->toBe($m2mGuard);
});

test('api-candidate guard returns null when no Authorization header is present', function (): void {
    $guard = Auth::guard('api-candidate');

    expect($guard->user())->toBeNull();
});
