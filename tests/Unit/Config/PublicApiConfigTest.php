<?php

declare(strict_types=1);

/**
 * Public API step 1 (docs/specs/public-api/SPEC.md §0, §3.2, §5.2, §7 step 1).
 *
 * Pins the shipped defaults of `config/public_api.php`, the same idiom as
 * `TruncationRetryConfigTest`/`AuditConfigTest`: changing a default requires
 * editing this test — deliberate and visible.
 */
test('shipped public_api defaults: contract_path, base_url, developers_url, interview_url, embed_cdn_url', function (): void {
    expect(config('public_api.contract_path'))->toBe(base_path('public-api/openapi.yaml'))
        ->and(config('public_api.base_url'))->toBe(env('APP_URL').'/api/v1')
        ->and(config('public_api.developers_url'))->toBeNull()
        ->and(config('public_api.interview_url'))->toBeNull()
        ->and(config('public_api.embed_cdn_url'))->toBeNull();
});

test('the vendored contract file exists at the configured contract_path', function (): void {
    expect(file_exists(config('public_api.contract_path')))->toBeTrue();
});
