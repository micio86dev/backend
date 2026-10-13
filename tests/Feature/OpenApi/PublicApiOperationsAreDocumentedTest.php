<?php

declare(strict_types=1);

/**
 * Every operation of the public `/v1` document has a summary and a description.
 *
 * Scramble takes both from the controller method's PHPDoc, so an undocumented method
 * ships an empty operation to the SDKs and the developer docs. Reads the committed
 * `openapi.v1.json` (CI proves it equals a fresh export). The fix for a failure is a
 * docblock on the controller method, then a re-export.
 */
test('every /v1 operation has a non-empty summary and description', function (): void {
    $document = json_decode((string) file_get_contents(base_path('openapi.v1.json')), true, 512, JSON_THROW_ON_ERROR);

    $undocumented = [];
    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            if (trim((string) ($operation['summary'] ?? '')) === '' || trim((string) ($operation['description'] ?? '')) === '') {
                $undocumented[] = strtoupper($method).' '.$path;
            }
        }
    }

    expect($undocumented)->toBe([]);
});

test('candidate.external_id documents its bound on the create-interview request', function (): void {
    $document = json_decode((string) file_get_contents(base_path('openapi.v1.json')), true, 512, JSON_THROW_ON_ERROR);

    $externalId = $document['components']['schemas']['CreateInterviewRequest']['properties']['candidate']['properties']['external_id'];

    expect($externalId['description'])->toContain('1 to 9007199254740991')
        ->and($externalId['minimum'])->toBe(1)
        ->and($externalId['maximum'])->toBe(9007199254740991);
});
