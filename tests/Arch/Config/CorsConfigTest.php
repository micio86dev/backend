<?php

/**
 * RED — 1.2: config/cors.php invariants (backoffice-session-refresh-hardening
 * D1). Same config-invariant pattern as tests/Unit/C10/WebhooksConfigTest.php.
 *
 * A wildcard anywhere in this file — literal `*` in origins/patterns/headers —
 * is the exact regression D1 exists to close, so every invariant here asserts
 * its ABSENCE rather than a specific present value.
 */
test('config/cors.php exists and is an array', function (): void {
    expect(config('cors'))->toBeArray();
});

test('allowed_origins is non-empty and contains no wildcard', function (): void {
    $origins = config('cors.allowed_origins');

    expect($origins)->toBeArray();
    expect($origins)->not->toBeEmpty();
    expect($origins)->not->toContain('*');
});

test('allowed_origins contains both Nuxt origins (backoffice AND frontend)', function (): void {
    $origins = config('cors.allowed_origins');

    // phpunit.xml pins CORS_ALLOWED_ORIGINS to both dev origins — an
    // allowlist covering only one Nuxt app is the primary blast radius D1
    // documents (the candidate `frontend` also calls api/*).
    expect($origins)->toContain('http://localhost:3001');
    expect($origins)->toContain('http://localhost:3000');
});

test('allowed_origins_patterns is deliberately empty — a regex is how a wildcard sneaks back in', function (): void {
    expect(config('cors.allowed_origins_patterns'))->toBe([]);
});

test('allowed_headers is enumerated, never a wildcard, and contains X-BEAI-Refresh', function (): void {
    $headers = config('cors.allowed_headers');

    expect($headers)->toBeArray();
    expect($headers)->not->toContain('*');
    expect($headers)->toContain('X-BEAI-Refresh');
});

test('allowed_methods is enumerated, never a wildcard', function (): void {
    expect(config('cors.allowed_methods'))->not->toContain('*');
});

test('supports_credentials is true — required for the refresh cookie, incompatible with wildcard origins', function (): void {
    expect(config('cors.supports_credentials'))->toBe(true);
});

test('max_age caches the preflight the custom CSRF header forces on every refresh', function (): void {
    expect(config('cors.max_age'))->toBe(3600);
});
