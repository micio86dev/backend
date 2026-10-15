<?php

declare(strict_types=1);

/**
 * The published contract must describe `primary_index` on the utterance 202
 * (live-interview-call-chrome, T-A3.1). The COMMITTED `openapi.json` is read the way the
 * frontend's codegen reads it: Scramble's derivation is silent when an annotation goes stale.
 *
 * REQ: interview-session "POST /utterance" - Primary index
 */
test('the utterance 202 documents boundary_due and a required integer primary_index', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $schema = $spec['paths']['/candidate/interview/utterance']['post']['responses']['202']['content']['application/json']['schema'];

    expect($schema['properties']['primary_index']['type'])->toBe('integer')
        ->and($schema['properties']['boundary_due']['type'])->toBe('boolean')
        ->and($schema['required'])->toBe(['boundary_due', 'primary_index']);
});
