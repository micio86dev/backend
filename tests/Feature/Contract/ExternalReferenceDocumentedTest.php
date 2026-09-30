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
 * The RESPONSE side is pinned the same way (slice A3a): every operator, M2M and
 * integration response that returns a participant must reference
 * `ParticipantEnrolmentResource`, whose `external_id` / `source` are typed and
 * always present, while the candidate session keeps the schema that carries
 * neither. A hand-written `@scramble-return` is the only thing that produces
 * these shapes, and it does not have to agree with the array the method
 * returns; a stale one fails nothing except a client's generated types.
 *
 * The READ side of the backoffice and of /v1 is pinned next (slice A3a-ii): the
 * admin list and detail schemas in `openapi.json`, and `PublicInterview` in
 * `openapi.v1.json`. The /v1 WRITE side closes the file (slice A3b): the
 * `candidate` object of the create-interview request, and the `external_id` /
 * `source` filters of the interview list, in BOTH the Scramble export and the
 * vendored `public-api/openapi.yaml`. `ContractEquivalenceTest` compares the
 * query parameter NAMES and the top-level request properties only, so the
 * nested `candidate` schema, and the type, bounds and wording of each filter,
 * are pinned here.
 *
 * REQ: External Reference Validation Is One Shared Contract,
 *      M2M Participant Create Accepts And Returns The External Reference,
 *      Surfaces That Never Carry The External Reference
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Support\Participant\ExternalReference;
use Symfony\Component\Yaml\Yaml;

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

// ---------------------------------------------------------------------------
// Response schemas (slice A3a)
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function externalReferenceSpec(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Every `$ref` reachable under one documented response, in document order.
 *
 * @param  array<string, mixed>  $spec
 * @return list<string>
 */
function externalReferenceResponseRefs(array $spec, string $path, string $method, string $status): array
{
    $schema = $spec['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema'] ?? null;
    expect($schema)->not->toBeNull("openapi.json documents no JSON {$status} response for {$method} {$path}");

    $refs = [];
    $collect = function (mixed $node) use (&$collect, &$refs): void {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            } else {
                $collect($value);
            }
        }
    };
    $collect($schema);

    return $refs;
}

/**
 * The routes whose response is a participant for an operator or an integration,
 * keyed by a readable description: `[path, method, status]`.
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function externalReferenceEnrolmentResponses(): array
{
    return [
        'M2M create' => ['/m2m/participants', 'post', '201'],
        'M2M index' => ['/m2m/participants', 'get', '200'],
        'M2M show' => ['/m2m/participants/{id}', 'get', '200'],
        'M2M reschedule' => ['/m2m/participants/{id}/schedule', 'patch', '200'],
        'M2M cancel schedule' => ['/m2m/participants/{id}/schedule', 'delete', '200'],
        'operator scheduled entry link' => ['/entry-links', 'post', '201'],
        'operator reschedule' => ['/participants/{id}/schedule', 'patch', '200'],
        'operator cancel schedule' => ['/participants/{id}/schedule', 'delete', '200'],
    ];
}

test('the operator and M2M participant responses reference the enrolment schema, never the candidate one', function (string $path, string $method, string $status): void {
    $refs = externalReferenceResponseRefs(externalReferenceSpec(), $path, $method, $status);

    expect($refs)->toContain('#/components/schemas/ParticipantEnrolmentResource');
    expect($refs)->not->toContain('#/components/schemas/App.Http.Resources.ParticipantResource');
})->with(fn () => externalReferenceEnrolmentResponses());

test('the enrolment schema documents external_id as integer|null and source as string|null, both required', function (): void {
    $schema = externalReferenceSpec()['components']['schemas']['ParticipantEnrolmentResource'] ?? null;

    expect($schema)->not->toBeNull('openapi.json declares no ParticipantEnrolmentResource schema');
    expect(externalReferenceTypeIs($schema['properties']['external_id'], 'integer'))->toBeTrue('external_id must be integer|null');
    expect(externalReferenceTypeIs($schema['properties']['source'], 'string'))->toBeTrue('source must be string|null');
    // Always present (null when absent): a consumer tells "no reference" from
    // "a server that predates the field" by the key, not by its value.
    expect($schema['required'])->toContain('external_id')->toContain('source');
});

test('the enrolment schema is the candidate schema plus exactly the two reference fields', function (): void {
    $schemas = externalReferenceSpec()['components']['schemas'];

    $enrolment = array_keys($schemas['ParticipantEnrolmentResource']['properties']);
    $candidate = array_keys($schemas['App.Http.Resources.ParticipantResource']['properties']);
    sort($enrolment);
    sort($candidate);

    expect(array_values(array_diff($enrolment, $candidate)))->toBe(['external_id', 'source']);
    expect(array_diff($candidate, $enrolment))->toBe([]);
});

test('the candidate session schema documents neither external_id nor source', function (): void {
    $spec = externalReferenceSpec();

    expect(externalReferenceResponseRefs($spec, '/candidate/session', 'get', '200'))
        ->toContain('#/components/schemas/App.Http.Resources.ParticipantResource');

    $properties = $spec['components']['schemas']['App.Http.Resources.ParticipantResource']['properties'];

    expect($properties)->not->toHaveKey('external_id')->not->toHaveKey('source');
});

// ---------------------------------------------------------------------------
// Admin read schemas and the public Interview (slice A3a-ii)
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function externalReferencePublicSpec(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.v1.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The resources that return a participant to the backoffice and to /v1
 * integrations, keyed by a readable name: `[file, schema]`.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function externalReferenceReadSchemas(): array
{
    return [
        'admin list row' => ['openapi.json', 'ParticipantResource'],
        'admin detail' => ['openapi.json', 'ParticipantDetailResource'],
        'public Interview' => ['openapi.v1.json', 'PublicInterview'],
    ];
}

test('the read schemas document external_id as integer|null and source as string|null, both required', function (string $file, string $schemaName): void {
    $spec = json_decode((string) file_get_contents(base_path($file)), true, flags: JSON_THROW_ON_ERROR);
    $schema = $spec['components']['schemas'][$schemaName] ?? null;

    expect($schema)->not->toBeNull("{$file} declares no {$schemaName} schema");
    expect(externalReferenceTypeIs($schema['properties']['external_id'] ?? [], 'integer'))->toBeTrue("{$schemaName}.external_id must be integer|null");
    expect(externalReferenceTypeIs($schema['properties']['source'] ?? [], 'string'))->toBeTrue("{$schemaName}.source must be string|null");
    // Always present (null when absent), so a consumer tells "no reference"
    // from "a server that predates the field" by the key.
    expect($schema['required'])->toContain('external_id')->toContain('source');
})->with(fn () => externalReferenceReadSchemas());

test('the candidate session schema still documents neither field after the admin resources gained them', function (): void {
    $properties = externalReferenceSpec()['components']['schemas']['App.Http.Resources.ParticipantResource']['properties'];

    expect($properties)->not->toHaveKey('external_id')->not->toHaveKey('source');
});

test('the admin list and detail endpoints reference the schemas that carry the fields', function (): void {
    $spec = externalReferenceSpec();

    expect(externalReferenceResponseRefs($spec, '/participants', 'get', '200'))
        ->toContain('#/components/schemas/ParticipantResource');
    expect(externalReferenceResponseRefs($spec, '/participants/{id}', 'get', '200'))
        ->toContain('#/components/schemas/ParticipantDetailResource');
});

// ---------------------------------------------------------------------------
// The /v1 create-interview request (slice A3b)
// ---------------------------------------------------------------------------

/**
 * The `candidate` object schema of the create-interview request, from the
 * Scramble export of `/v1` (`$ref` followed) or from the vendored contract.
 *
 * @return array<string, mixed>
 */
function externalReferenceCreateCandidate(string $document): array
{
    if ($document === 'contract') {
        $contract = Yaml::parseFile(config('public_api.contract_path'));

        return $contract['components']['schemas']['CreateInterviewRequest']['properties']['candidate'];
    }

    $spec = externalReferencePublicSpec();
    $schema = $spec['paths']['/interviews']['post']['requestBody']['content']['application/json']['schema'];

    if (isset($schema['$ref'])) {
        $schema = $spec['components']['schemas'][basename((string) $schema['$ref'])];
    }

    return $schema['properties']['candidate'];
}

/**
 * The two documents a consumer reads, keyed by a readable name.
 *
 * @return array<string, array{0: string}>
 */
function externalReferencePublicDocuments(): array
{
    return [
        'the /v1 Scramble export (openapi.v1.json)' => ['export'],
        'the vendored contract (public-api/openapi.yaml)' => ['contract'],
    ];
}

test('the create-interview request documents candidate.external_id as integer|null within 1 and 2^53-1', function (string $document): void {
    $candidate = externalReferenceCreateCandidate($document);
    $property = $candidate['properties']['external_id'] ?? null;

    expect($property)->not->toBeNull("{$document}: candidate.external_id is not documented");
    expect(externalReferenceTypeIs($property, 'integer'))->toBeTrue('candidate.external_id must be integer|null');
    expect($property['minimum'])->toEqual(1);
    expect((float) $property['maximum'])->toBe((float) ExternalReference::MAX_EXTERNAL_ID);
    // Optional: an enrolment without a reference is the ordinary case.
    expect($candidate['required'] ?? [])->not->toContain('external_id');
})->with(fn () => externalReferencePublicDocuments());

test('the create-interview request documents candidate.source as string|null of at most 180 characters', function (string $document): void {
    $candidate = externalReferenceCreateCandidate($document);
    $property = $candidate['properties']['source'] ?? null;

    expect($property)->not->toBeNull("{$document}: candidate.source is not documented");
    expect(externalReferenceTypeIs($property, 'string'))->toBeTrue('candidate.source must be string|null');
    expect($property['maxLength'])->toBe(ExternalReference::SOURCE_MAX_LENGTH);
    expect($candidate['required'] ?? [])->not->toContain('source');
})->with(fn () => externalReferencePublicDocuments());

// ---------------------------------------------------------------------------
// The /v1 list filters (slice A3b)
// ---------------------------------------------------------------------------

/**
 * One query parameter of `GET /interviews`, from the Scramble export of `/v1`
 * or from the vendored contract.
 *
 * @return array<string, mixed>
 */
function externalReferenceListParameter(string $document, string $name): array
{
    $source = $document === 'contract'
        ? Yaml::parseFile(config('public_api.contract_path'))
        : externalReferencePublicSpec();

    foreach ($source['paths']['/interviews']['get']['parameters'] as $parameter) {
        if (($parameter['name'] ?? null) === $name && ($parameter['in'] ?? null) === 'query') {
            return $parameter;
        }
    }

    return [];
}

test('GET /interviews documents the external_id filter as an integer query parameter with a description', function (string $document): void {
    $parameter = externalReferenceListParameter($document, 'external_id');

    expect($parameter)->not->toBe([], "{$document}: listInterviews has no external_id query parameter");
    expect($parameter['schema']['type'])->toBe('integer');
    expect($parameter['description'] ?? '')->toBeString()->not->toBe('');
    // The filter is optional.
    expect($parameter['required'] ?? false)->toBeFalse();
})->with(fn () => externalReferencePublicDocuments());

test('GET /interviews documents the source filter as a string query parameter with a description', function (string $document): void {
    $parameter = externalReferenceListParameter($document, 'source');

    expect($parameter)->not->toBe([], "{$document}: listInterviews has no source query parameter");
    expect($parameter['schema']['type'])->toBe('string');
    expect($parameter['description'] ?? '')->toBeString()->not->toBe('');
    expect($parameter['required'] ?? false)->toBeFalse();
})->with(fn () => externalReferencePublicDocuments());

test('the vendored contract bounds the filters like the request body: external_id 1 to 2^53-1, source 180 characters', function (): void {
    $externalId = externalReferenceListParameter('contract', 'external_id');
    $source = externalReferenceListParameter('contract', 'source');

    expect($externalId['schema']['minimum'])->toBe(1);
    expect($externalId['schema']['maximum'])->toBe(ExternalReference::MAX_EXTERNAL_ID);
    expect($source['schema']['maxLength'])->toBe(ExternalReference::SOURCE_MAX_LENGTH);
});

test('the exported filter descriptions are consumer-facing, not maintainer notes lifted from a code comment', function (string $name): void {
    $description = (string) externalReferenceListParameter('export', $name)['description'];

    // Scramble lifts the nearest code comment into a parameter's description
    // when the parameter has none of its own; these are the words that only a
    // maintainer comment would carry.
    expect($description)
        ->not->toContain('validateFilterFormats')
        ->not->toContain('ConvertEmptyStringsToNull')
        ->not->toContain('base query');
    expect($description)->toContain('400');
})->with(['external_id', 'source']);
