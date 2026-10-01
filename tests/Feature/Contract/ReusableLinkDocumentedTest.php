<?php

declare(strict_types=1);

/**
 * The published contract must describe the reusable link admin operations
 * (reusable-interview-links, B2).
 *
 * Scramble derives the operations from the controller and the resource from a
 * hand-written `@scramble-return`, and that derivation is silent: a stale or
 * missing annotation still exports, CI's fresh-export diff compares the export
 * to itself, and the two Nuxt apps generate a typed client that disagrees with
 * the API. So the COMMITTED `openapi.json` is read here, the way a client's
 * codegen reads it.
 *
 * The one thing this file guards above all: no schema may describe a secret.
 * The raw token exists only inside `entry_url` of the creation response; the
 * hash, an expiry and the internal id appear in no schema at all.
 *
 * REQ: The Contract Is Exported And The Public API Is Unchanged,
 *      Creating A Link, Listing Links Never Discloses The Token Or Its Hash
 *      (sdd/reusable-interview-links/spec/reusable-interview-links)
 */

/**
 * @return array<string, mixed>
 */
function reusableLinkDocumentedSpec(string $file = 'openapi.json'): array
{
    return json_decode((string) file_get_contents(base_path($file)), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The exported operation of one route.
 *
 * @return array<string, mixed>
 */
function reusableLinkDocumentedOperation(string $path, string $method): array
{
    $operation = reusableLinkDocumentedSpec()['paths'][$path][$method] ?? null;

    expect($operation)->not->toBeNull("openapi.json documents no {$method} {$path}");

    return $operation;
}

/**
 * The schema of a resource, with its properties keyed by name.
 *
 * @return array<string, mixed>
 */
function reusableLinkDocumentedSchema(string $name): array
{
    $schema = reusableLinkDocumentedSpec()['components']['schemas'][$name] ?? null;

    expect($schema)->not->toBeNull("openapi.json declares no schema named {$name}");

    return $schema;
}

/**
 * Both ways a schema may say "this or null".
 *
 * @param  array<string, mixed>  $property
 */
function reusableLinkDocumentedTypeIs(array $property, string $type): bool
{
    $declared = (array) ($property['type'] ?? []);
    sort($declared);

    $expected = [$type, 'null'];
    sort($expected);

    return $declared === $expected;
}

const REUSABLE_LINK_PROJECT_PATH = '/projects/{project}/reusable-links';

// ─── Create ──────────────────────────────────────────────────────────────────

test('the create operation is documented under its operation id', function (): void {
    $operation = reusableLinkDocumentedOperation(REUSABLE_LINK_PROJECT_PATH, 'post');

    expect($operation['operationId'])->toBe('reusableInterviewLink.store');
    expect($operation['summary'] ?? '')->not->toBe('');
    // Consumer-facing wording, not a maintainer note lifted from a code comment.
    expect((string) ($operation['description'] ?? ''))->toContain('shown ONCE');
});

test('the create request documents label as an optional nullable string of at most 120 characters', function (): void {
    $schema = reusableLinkDocumentedOperation(REUSABLE_LINK_PROJECT_PATH, 'post')['requestBody']['content']['application/json']['schema'];
    $label = $schema['properties']['label'] ?? null;

    expect($label)->not->toBeNull('the create request does not document label');
    expect(reusableLinkDocumentedTypeIs($label, 'string'))->toBeTrue('label must be string|null');
    expect($label['maxLength'])->toBe(120);
    expect($schema['required'] ?? [])->not->toContain('label');
    // Everything else about a link is decided by the server.
    expect(array_keys($schema['properties']))->toBe(['label']);
});

test('the 201 response carries the resource and the one-time entry_url, both required', function (): void {
    $schema = reusableLinkDocumentedOperation(REUSABLE_LINK_PROJECT_PATH, 'post')['responses']['201']['content']['application/json']['schema'];

    expect($schema['properties']['data']['$ref'])->toBe('#/components/schemas/ReusableInterviewLinkResource');
    expect($schema['properties']['entry_url']['type'])->toBe('string');
    expect($schema['required'])->toContain('data')->toContain('entry_url');
    // The bare token is never a field of its own.
    expect(array_keys($schema['properties']))->toEqualCanonicalizing(['data', 'entry_url']);
});

test('the create operation documents its refusals', function (): void {
    $responses = reusableLinkDocumentedOperation(REUSABLE_LINK_PROJECT_PATH, 'post')['responses'];

    // JSON object keys that look like integers decode to int keys.
    expect(array_map('strval', array_keys($responses)))->toContain('201', '401', '403', '404', '409', '422');
});

// ─── Resource ────────────────────────────────────────────────────────────────

test('the resource exposes exactly the metadata of a link and never a secret, an expiry or an internal id', function (): void {
    $schema = reusableLinkDocumentedSchema('ReusableInterviewLinkResource');
    $properties = array_keys($schema['properties']);
    sort($properties);

    expect($properties)->toBe([
        'created_at',
        'created_by',
        'disabled_at',
        'id',
        'label',
        'lang',
        'last_used_at',
        'status',
        'token_prefix',
        'uses_count',
    ]);

    foreach (['token', 'link_token', 'token_hash', 'entry_url', 'expires_at', 'ttl', 'expires_in', 'organization_id', 'project_id'] as $forbidden) {
        expect($properties)->not->toContain($forbidden);
    }

    // `id` is the public identifier, a string, never the internal integer.
    expect($schema['properties']['id']['type'])->toBe('string');
    expect($schema['required'])->toEqualCanonicalizing($properties);
});

test('the resource documents status as active or disabled and the creator as a name only', function (): void {
    $properties = reusableLinkDocumentedSchema('ReusableInterviewLinkResource')['properties'];

    expect($properties['status']['enum'])->toBe(['active', 'disabled']);
    expect($properties['uses_count']['type'])->toBe('integer');
    expect(reusableLinkDocumentedTypeIs($properties['last_used_at'], 'string'))->toBeTrue();
    expect(reusableLinkDocumentedTypeIs($properties['disabled_at'], 'string'))->toBeTrue();
    expect(reusableLinkDocumentedTypeIs($properties['label'], 'string'))->toBeTrue();

    expect(reusableLinkDocumentedTypeIs($properties['created_by'], 'object'))->toBeTrue('created_by must be an object or null');
    expect(array_keys($properties['created_by']['properties']))->toBe(['name']);
});

// ─── The public API is untouched ─────────────────────────────────────────────

test('the public v1 export names no reusable link path', function (): void {
    $paths = array_keys(reusableLinkDocumentedSpec('openapi.v1.json')['paths']);

    foreach ($paths as $path) {
        expect($path)->not->toContain('reusable');
    }
    expect(array_keys(reusableLinkDocumentedSpec('openapi.v1.json')['components']['schemas']))
        ->not->toContain('ReusableInterviewLinkResource');
});
