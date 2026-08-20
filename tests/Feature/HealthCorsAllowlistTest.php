<?php

declare(strict_types=1);

/**
 * GET /api/health fail-loud CORS gate (deploy incident 2026-08-20 — see
 * App\Support\Http\CorsAllowlistStatus and config/cors.php). Complements
 * tests/Feature/HealthTest.php, which only covers the healthy path.
 */
test('health check fails loudly (503) when the CORS allowlist is empty in a production-like environment', function (): void {
    app()['env'] = 'production';
    config(['cors.allowed_origins' => []]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(503)
        ->assertExactJson([
            'status' => 'down',
            'reason' => 'cors_allowed_origins_empty',
        ]);
});

test('health check boots normally (200) when the CORS allowlist is populated in a production-like environment', function (): void {
    app()['env'] = 'production';
    config(['cors.allowed_origins' => ['https://backoffice.example.com', 'https://app.example.com']]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertExactJson(['status' => 'ok']);
});

test('local environment is unaffected by an empty CORS allowlist', function (): void {
    app()['env'] = 'local';
    config(['cors.allowed_origins' => []]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertExactJson(['status' => 'ok']);
});

test('testing environment is unaffected by an empty CORS allowlist', function (): void {
    // APP_ENV is already 'testing' per phpunit.xml; set it explicitly rather
    // than relying on that default holding.
    app()['env'] = 'testing';
    config(['cors.allowed_origins' => []]);

    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertExactJson(['status' => 'ok']);
});
