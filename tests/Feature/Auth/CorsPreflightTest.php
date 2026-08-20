<?php

/**
 * RED — 1.1: CORS preflight behaviour (backoffice-session-refresh-hardening D1).
 *
 * api-cors spec Req 1 ("Explicit Origin Allowlist, No Wildcard") and Req 3
 * ("Credentialed CORS Support"). Both Nuxt dev origins (backoffice :3001,
 * frontend :3000 — phpunit.xml's CORS_ALLOWED_ORIGINS) MUST be present: an
 * allowlist covering only the backoffice would silently take the candidate
 * app down.
 */

test('preflight from the allowlisted backoffice origin gets Access-Control-Allow-Origin', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3001',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'Content-Type,X-BEAI-Refresh',
    ])->options('/api/auth/refresh');

    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3001');
    $response->assertHeader('Access-Control-Allow-Credentials', 'true');
});

test('preflight from the allowlisted frontend (candidate) origin gets Access-Control-Allow-Origin', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3000',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'Content-Type',
    ])->options('/api/sso/exchange');

    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
});

test('preflight from an unlisted origin does NOT get Access-Control-Allow-Origin', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'https://evil.example.com',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'Content-Type',
    ])->options('/api/auth/refresh');

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('actual (non-preflight) request from an allowlisted origin echoes it back on the response', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3001',
    ])->getJson('/api/health');

    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3001');
    $response->assertHeader('Access-Control-Allow-Credentials', 'true');
});

test('actual request from an unlisted origin does NOT get Access-Control-Allow-Origin', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'https://evil.example.com',
    ])->getJson('/api/health');

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('preflight caches for max_age so the custom CSRF header does not force a preflight on every request', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3001',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'X-BEAI-Refresh',
    ])->options('/api/auth/refresh');

    $response->assertHeader('Access-Control-Max-Age', '3600');
});

test('preflight allows the enumerated X-BEAI-Refresh header explicitly, never via wildcard', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3001',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'X-BEAI-Refresh',
    ])->options('/api/auth/refresh');

    $allowHeaders = (string) $response->headers->get('Access-Control-Allow-Headers');

    expect($allowHeaders)->not->toBe('*');
    expect(mb_strtolower($allowHeaders))->toContain('x-beai-refresh');
});
