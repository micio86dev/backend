<?php

declare(strict_types=1);

/**
 * The published request contract must document the external reference
 * (candidate-external-reference).
 *
 * The three internal write surfaces spread `ExternalReference::rules()` into an
 * inline `validate()` call, and Scramble derives each exported requestBody from
 * that call site. That derivation is silent: if Scramble stopped evaluating the
 * spread, the export would still succeed, CI's fresh-export diff would compare
 * the export to itself, and the two Nuxt apps would generate a typed client
 * missing both fields. So the COMMITTED `openapi.json` is read here, as a
 * client's codegen would read it.
 *
 * Later slices extend this file with the response schemas and the public
 * `openapi.v1.json`.
 *
 * REQ: External Reference Validation Is One Shared Contract
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Support\Participant\ExternalReference;

/**
 * @return array<string, mixed>
 */
function externalReferenceRequestProperties(string $path): array
{
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    $schema = $spec['paths'][$path]['post']['requestBody']['content']['application/json']['schema'] ?? null;
    expect($schema)->not->toBeNull("openapi.json documents no JSON request body for POST {$path}");

    return $schema;
}

/**
 * Both ways a schema may say "this or null": `type: [x, null]` (OpenAPI 3.1).
 *
 * @param  array<string, mixed>  $property
 */
function externalReferenceTypeIs(array $property, string $type): bool
{
    $declared = (array) ($property['type'] ?? []);
    sort($declared);

    $expected = [$type, 'null'];
    sort($expected);

    return $declared === $expected;
}

/**
 * The internal request surfaces that accept the reference, keyed by a readable
 * description; each value is the path as it appears in `openapi.json`.
 *
 * @return array<string, array{0: string}>
 */
function externalReferenceRequestSurfaces(): array
{
    return [
        'operator entry link' => ['/entry-links'],
        'M2M participant create' => ['/m2m/participants'],
        'M2M sso-link mint' => ['/m2m/sso-link'],
    ];
}

test('the request body documents external_id as a nullable integer within 1 and 2^53-1', function (string $path): void {
    $schema = externalReferenceRequestProperties($path);
    $property = $schema['properties']['external_id'] ?? null;

    expect($property)->not->toBeNull("POST {$path} does not document external_id");
    expect(externalReferenceTypeIs($property, 'integer'))->toBeTrue('external_id must be integer|null');
    expect($property['minimum'])->toEqual(1);
    // Scramble casts `max:` parameters to float, so the exported maximum may be
    // written as 9.007199254740991e+15. 2^53-1 is exactly representable in a
    // float64, so comparing as floats is exact, not approximate.
    expect((float) $property['maximum'])->toBe((float) ExternalReference::MAX_EXTERNAL_ID);
    expect($schema['required'] ?? [])->not->toContain('external_id');
})->with(fn () => externalReferenceRequestSurfaces());

test('the request body documents source as a nullable string of at most 180 characters', function (string $path): void {
    $schema = externalReferenceRequestProperties($path);
    $property = $schema['properties']['source'] ?? null;

    expect($property)->not->toBeNull("POST {$path} does not document source");
    expect(externalReferenceTypeIs($property, 'string'))->toBeTrue('source must be string|null');
    expect($property['maxLength'])->toBe(ExternalReference::SOURCE_MAX_LENGTH);
    expect($schema['required'] ?? [])->not->toContain('source');
})->with(fn () => externalReferenceRequestSurfaces());
