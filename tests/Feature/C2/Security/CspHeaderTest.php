<?php

/**
 * CSP header tests (C2 — SecurityHeaders middleware update).
 *
 * Asserts:
 * - CSP includes frame-ancestors and connect-src from BACKOFFICE_ORIGIN (config)
 * - Safe default (block-all: 'none') when BACKOFFICE_ORIGIN is unset
 * - Wildcard '*' origin is rejected (substitutes safe default)
 *
 * NOTE: SecurityHeaders reads from config('services.backoffice_origin') (not env())
 * to comply with Larastan's noEnvCallsOutsideOfConfig rule. Tests set config values
 * via Config::set() — not putenv() — to match this resolution path.
 */

use Illuminate\Support\Facades\Config;

test('CSP includes frame-ancestors from BACKOFFICE_ORIGIN when set', function (): void {
    $origin = 'https://backoffice.example.com';
    Config::set('services.backoffice_origin', $origin);

    $response = $this->getJson('/api/health');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    expect($csp)->toContain("frame-ancestors 'self' {$origin}");
});

test('CSP includes connect-src from BACKOFFICE_ORIGIN when set', function (): void {
    $origin = 'https://backoffice.example.com';
    Config::set('services.backoffice_origin', $origin);

    $response = $this->getJson('/api/health');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    expect($csp)->toContain("connect-src 'self' {$origin}");
});

test('CSP safe default block-all when BACKOFFICE_ORIGIN is unset', function (): void {
    Config::set('services.backoffice_origin', '');

    $response = $this->getJson('/api/health');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    expect($csp)->toContain("frame-ancestors 'none'");
    expect($csp)->toContain("connect-src 'none'");
});

test('CSP rejects wildcard origin and substitutes safe default', function (): void {
    Config::set('services.backoffice_origin', '*');

    $response = $this->getJson('/api/health');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    // Wildcard must NOT appear in the CSP
    expect($csp)->not->toContain('frame-ancestors *');
    expect($csp)->not->toContain('connect-src *');
    // Safe fallback must be applied
    expect($csp)->toContain("frame-ancestors 'none'");
});
