<?php

declare(strict_types=1);

/**
 * App\Support\Http\CorsAllowlistStatus (CORS fail-loud, deploy incident
 * 2026-08-20 — see class docblock and config/cors.php).
 */

use App\Support\Http\CorsAllowlistStatus;

test('reports misconfigured when the allowlist is empty in a production-like environment', function (): void {
    app()['env'] = 'production';
    config(['cors.allowed_origins' => []]);

    expect(CorsAllowlistStatus::isMisconfigured())->toBeTrue();
});

test('reports healthy when the allowlist is populated in a production-like environment', function (): void {
    app()['env'] = 'production';
    config(['cors.allowed_origins' => ['https://backoffice.example.com']]);

    expect(CorsAllowlistStatus::isMisconfigured())->toBeFalse();
});

test('local is exempt even with an empty allowlist', function (): void {
    app()['env'] = 'local';
    config(['cors.allowed_origins' => []]);

    expect(CorsAllowlistStatus::isMisconfigured())->toBeFalse();
});

test('testing is exempt even with an empty allowlist', function (): void {
    app()['env'] = 'testing';
    config(['cors.allowed_origins' => []]);

    expect(CorsAllowlistStatus::isMisconfigured())->toBeFalse();
});
