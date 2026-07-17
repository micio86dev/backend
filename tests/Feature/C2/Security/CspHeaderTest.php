<?php

/**
 * CSP header tests (C2 — SecurityHeaders middleware update).
 *
 * Asserts:
 * - CSP includes frame-ancestors and connect-src from BACKOFFICE_ORIGIN env
 * - Safe default (block-all: 'none') when BACKOFFICE_ORIGIN is unset
 * - Wildcard '*' origin is rejected (substitutes safe default)
 */

use Illuminate\Support\Facades\Config;

// Helper: temporarily set BACKOFFICE_ORIGIN for a test, then restore.
function withBackofficeOrigin(string $origin, Closure $callback): void
{
    $previous = getenv('BACKOFFICE_ORIGIN');
    putenv("BACKOFFICE_ORIGIN={$origin}");

    try {
        $callback();
    } finally {
        if ($previous === false) {
            putenv('BACKOFFICE_ORIGIN');
        } else {
            putenv("BACKOFFICE_ORIGIN={$previous}");
        }
    }
}

test('CSP includes frame-ancestors from BACKOFFICE_ORIGIN when set', function (): void {
    $origin = 'https://backoffice.example.com';

    withBackofficeOrigin($origin, function () use ($origin): void {
        $response = $this->getJson('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');
        expect($csp)->not->toBeNull();
        expect($csp)->toContain("frame-ancestors 'self' {$origin}");
    });
});

test('CSP includes connect-src from BACKOFFICE_ORIGIN when set', function (): void {
    $origin = 'https://backoffice.example.com';

    withBackofficeOrigin($origin, function () use ($origin): void {
        $response = $this->getJson('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');
        expect($csp)->not->toBeNull();
        expect($csp)->toContain("connect-src 'self' {$origin}");
    });
});

test("CSP safe default block-all when BACKOFFICE_ORIGIN is unset", function (): void {
    withBackofficeOrigin('', function (): void {
        $response = $this->getJson('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');
        expect($csp)->not->toBeNull();
        expect($csp)->toContain("frame-ancestors 'none'");
        expect($csp)->toContain("connect-src 'none'");
    });
});

test('CSP rejects wildcard origin and substitutes safe default', function (): void {
    withBackofficeOrigin('*', function (): void {
        $response = $this->getJson('/api/health');

        $csp = $response->headers->get('Content-Security-Policy');
        expect($csp)->not->toBeNull();
        // Wildcard must NOT appear in the CSP
        expect($csp)->not->toContain('frame-ancestors *');
        expect($csp)->not->toContain("connect-src *");
        // Safe fallback must be applied
        expect($csp)->toContain("frame-ancestors 'none'");
    });
});
