<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Guards the vendored copy of the Public API contract (SPEC.md §0 "Contract
 * governance"): `public-api/openapi.yaml` here MUST stay byte-for-byte equal
 * to the wrapper's `docs/specs/public-api/openapi.yaml`, which remains the
 * source of truth. A wrapper CI guard asserts the equality; this test only
 * guards that the vendored copy is present and structurally sane.
 */
test('T-CONTRACT-005: the vendored openapi.yaml parses and declares openapi 3.1.0 and the /health path', function (): void {
    $contractPath = config('public_api.contract_path');

    expect(file_exists($contractPath))->toBeTrue("Vendored contract not found at {$contractPath}.");

    $contract = Yaml::parseFile($contractPath);

    expect($contract)->toBeArray()
        ->and($contract['openapi'])->toBe('3.1.0')
        ->and($contract['paths'])->toHaveKey('/health');
});
