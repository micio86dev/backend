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

// ─── List ────────────────────────────────────────────────────────────────────

test('the list operation is documented and returns an unpaginated collection of the resource', function (): void {
    $operation = reusableLinkDocumentedOperation(REUSABLE_LINK_PROJECT_PATH, 'get');

    expect($operation['operationId'])->toBe('reusableInterviewLink.index');
    expect($operation['summary'] ?? '')->not->toBe('');

    $schema = $operation['responses']['200']['content']['application/json']['schema'];

    expect($schema['properties']['data']['type'])->toBe('array');
    expect($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/ReusableInterviewLinkResource');
    // Bounded per project, so the whole set is one array and carries no paging.
    expect(array_keys($schema['properties']))->toBe(['data']);
    expect(array_map('strval', array_keys($operation['responses'])))->toContain('200', '401', '403', '404');
});

// ─── Disable ─────────────────────────────────────────────────────────────────

test('the disable operation is documented as an idempotent 204 addressed by the public link id', function (): void {
    $operation = reusableLinkDocumentedOperation(REUSABLE_LINK_PROJECT_PATH.'/{link}', 'delete');

    expect($operation['operationId'])->toBe('reusableInterviewLink.destroy');
    expect($operation['summary'] ?? '')->not->toBe('');
    expect((string) ($operation['description'] ?? ''))->toContain('204');

    $parameters = collect($operation['parameters'])->keyBy('name');
    expect($parameters['project']['schema']['type'])->toBe('integer');
    // The public id (`rlk_...`), never the internal integer.
    expect($parameters['link']['schema']['type'])->toBe('string');

    expect(array_map('strval', array_keys($operation['responses'])))->toContain('204', '401', '403', '404', '409');
    expect($operation['responses']['204'])->not->toHaveKey('content');
});

test('the admin surface of a link is exactly create, list and disable', function (): void {
    $paths = reusableLinkDocumentedSpec()['paths'];
    $operations = [];

    foreach ($paths as $path => $methods) {
        if (str_starts_with($path, REUSABLE_LINK_PROJECT_PATH)) {
            foreach (array_keys($methods) as $method) {
                $operations[] = strtoupper($method).' '.$path;
            }
        }
    }
    sort($operations);

    expect($operations)->toBe([
        'DELETE /projects/{project}/reusable-links/{link}',
        'GET /projects/{project}/reusable-links',
        'POST /projects/{project}/reusable-links',
    ]);
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

// ─── Redeem ──────────────────────────────────────────────────────────────────

const REUSABLE_LINK_REDEEM_PATH = '/reusable-links/redeem';

test('the redeem operation is documented as the one public reusable link operation', function (): void {
    $operation = reusableLinkDocumentedOperation(REUSABLE_LINK_REDEEM_PATH, 'post');

    expect($operation['operationId'])->toBe('reusableLinkRedeem.redeem');
    expect($operation['summary'] ?? '')->not->toBe('');
    // The only other reusable path is the admin surface; redeem is not part of it.
    $others = [];
    foreach (reusableLinkDocumentedSpec()['paths'] as $path => $methods) {
        if (str_contains($path, 'reusable') && ! str_starts_with($path, REUSABLE_LINK_PROJECT_PATH)) {
            foreach (array_keys($methods) as $method) {
                $others[] = strtoupper($method).' '.$path;
            }
        }
    }
    expect($others)->toBe(['POST /reusable-links/redeem']);
});

test('the redeem request documents the link token and the visitor identity as required, and no field named token', function (): void {
    $schema = reusableLinkDocumentedOperation(REUSABLE_LINK_REDEEM_PATH, 'post')['requestBody']['content']['application/json']['schema'];

    expect(array_keys($schema['properties']))->toEqualCanonicalizing(['link_token', 'display_name', 'email']);
    expect($schema['properties']['link_token']['type'])->toBe('string');
    expect($schema['required'])->toEqualCanonicalizing(['link_token', 'display_name', 'email']);

    // The format is described in words: Scramble's `BodyParameter` has no way to
    // state a `pattern`, so the contract carries the shape in the description
    // rather than pretending to enforce it (recorded for the spec reconciliation).
    $description = (string) ($schema['properties']['link_token']['description'] ?? '');
    expect($description)->toContain('beai_rl_')->toContain('43');
});

test('the redeem request bounds the visitor identity: an email address and a name, 255 characters each', function (): void {
    $properties = reusableLinkDocumentedOperation(REUSABLE_LINK_REDEEM_PATH, 'post')['requestBody']['content']['application/json']['schema']['properties'];

    expect($properties['email']['type'])->toBe('string')
        ->and($properties['email']['format'])->toBe('email')
        ->and($properties['email']['maxLength'])->toBe(255)
        ->and($properties['display_name']['type'])->toBe('string')
        ->and($properties['display_name']['maxLength'])->toBe(255);
});

test('the redeem operation documents the 422 of an invalid identity with its field errors', function (): void {
    $responses = reusableLinkDocumentedOperation(REUSABLE_LINK_REDEEM_PATH, 'post')['responses'];

    expect(array_map('strval', array_keys($responses)))->toContain('422');

    // Scramble documents the framework's standard validation body once, as a
    // shared component, and points every validating operation at it.
    expect($responses['422']['$ref'])->toBe('#/components/responses/ValidationException');

    $schema = reusableLinkDocumentedSpec()['components']['responses']['ValidationException']['content']['application/json']['schema'];
    expect(array_keys($schema['properties']))->toEqualCanonicalizing(['message', 'errors'])
        ->and($schema['required'])->toEqualCanonicalizing(['message', 'errors']);
});

test('the redeem operation documents 200, 403, 404 and 429 with typed bodies', function (): void {
    $responses = reusableLinkDocumentedOperation(REUSABLE_LINK_REDEEM_PATH, 'post')['responses'];

    expect(array_map('strval', array_keys($responses)))->toContain('200', '403', '404', '429');

    $ok = $responses['200']['content']['application/json']['schema'];
    expect(array_keys($ok['properties']))->toBe(['access_token']);
    expect($ok['properties']['access_token']['type'])->toBe('string');
    expect($ok['required'])->toBe(['access_token']);

    $forbidden = $responses['403']['content']['application/json']['schema'];
    expect(array_keys($forbidden['properties']))->toEqualCanonicalizing(['message', 'redirect_url']);
    expect(reusableLinkDocumentedTypeIs($forbidden['properties']['redirect_url'], 'string'))->toBeTrue();

    foreach (['404', '429'] as $status) {
        $schema = $responses[$status]['content']['application/json']['schema'];
        expect(array_keys($schema['properties']))->toBe(['message']);
    }
});

test('the redeem operation never documents the secret as anything but the request field', function (): void {
    $encoded = json_encode(reusableLinkDocumentedOperation(REUSABLE_LINK_REDEEM_PATH, 'post')['responses'], JSON_THROW_ON_ERROR);

    foreach (['link_token', 'token_hash', 'entry_url', 'rlk_', 'reusable_interview_link'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
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

// ─── The admin participant origin marker (B4) ────────────────────────────────

/**
 * The admin participant resources that carry the reusable link origin, keyed by
 * a readable name: the schema each is exported under.
 *
 * @return array<string, array{0: string}>
 */
function reusableLinkDocumentedMarkerSchemas(): array
{
    return [
        'admin list row' => ['ParticipantResource'],
        'admin detail' => ['ParticipantDetailResource'],
    ];
}

test('the admin participant schemas document reusable_link as an object with an id and a nullable label, or null, always present', function (string $schemaName): void {
    $schema = reusableLinkDocumentedSchema($schemaName);
    $marker = $schema['properties']['reusable_link'] ?? null;

    expect($marker)->not->toBeNull("{$schemaName} does not document reusable_link");
    expect(reusableLinkDocumentedTypeIs($marker, 'object'))->toBeTrue("{$schemaName}.reusable_link must be object|null");
    // Exactly the two keys the admin read exposes, nothing about the link's internals.
    expect(array_keys($marker['properties']))->toBe(['id', 'label']);
    expect($marker['properties']['id']['type'])->toBe('string');
    expect(reusableLinkDocumentedTypeIs($marker['properties']['label'], 'string'))->toBeTrue("{$schemaName}.reusable_link.label must be string|null");
    expect($marker['required'])->toEqualCanonicalizing(['id', 'label']);
    // Present on every row (null for an ordinary participant), so a consumer
    // tells "not from a link" from "a server that predates the field" by the key.
    expect($schema['required'])->toContain('reusable_link');
})->with(fn () => reusableLinkDocumentedMarkerSchemas());

test('the marker is documented on the admin participant schemas only, never on a candidate, M2M or public schema', function (): void {
    $schemas = reusableLinkDocumentedSpec()['components']['schemas'];

    foreach (['App.Http.Resources.ParticipantResource', 'ParticipantEnrolmentResource'] as $name) {
        expect($schemas[$name]['properties'])->not->toHaveKey('reusable_link');
    }

    $public = reusableLinkDocumentedSpec('openapi.v1.json')['components']['schemas'];
    foreach ($public as $name => $schema) {
        expect($schema['properties'] ?? [])->not->toHaveKey('reusable_link');
        expect($schema['properties'] ?? [])->not->toHaveKey('reusable_interview_link_id');
    }
});
