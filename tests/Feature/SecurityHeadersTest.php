<?php

/**
 * Security headers tests (D29, task 7.7).
 *
 * Asserts that the SecurityHeaders middleware applies the required headers
 * on every API response.
 *
 * RED phase: these tests fail because SecurityHeaders middleware does not exist yet.
 */

test('GET /api/health includes X-Frame-Options header', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertHeader('X-Frame-Options', 'DENY');
});

test('GET /api/health includes X-Content-Type-Options header', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('GET /api/health includes Referrer-Policy header', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('GET /api/health includes Permissions-Policy header', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
});

test('GET /api/health over HTTPS includes HSTS header', function (): void {
    // Simulate an HTTPS request using the Symfony server variable 'HTTPS' = 'on'
    // which Symfony's Request::isSecure() checks directly.
    $response = $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => '443'])
        ->getJson('https://localhost/api/health');

    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('GET /api/health over HTTP does not include HSTS header', function (): void {
    $response = $this->getJson('/api/health');

    // HSTS must not be sent over plain HTTP (browsers would cache the HSTS policy)
    $response->assertHeaderMissing('Strict-Transport-Security');
});
