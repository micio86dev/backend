<?php

declare(strict_types=1);

/**
 * `POST /v1/interviews` accepts the calling system's external reference
 * (candidate-external-reference, slice A3b).
 *
 * `candidate.external_id` (integer) and `candidate.source` (string) are both
 * optional and independent: an enrolment may carry either, both or neither.
 * They are stored on the enrolment and echoed on the created Interview, which
 * always carries both keys (`null` when absent).
 *
 * The request is a JSON body, so `external_id` is validated with
 * `integer:strict`: a numeric string, a float or a boolean is refused rather
 * than coerced. A refusal is the ordinary `422 validation_failed` problem whose
 * `errors[].field` names the NESTED field (`candidate.external_id`), the same
 * shape every other `candidate.*` field already answers. Neither field is a
 * uniqueness key: a duplicate is decided by `email` and `candidate_ref` alone.
 *
 * REQ: Create Interview Accepts An Optional External Reference
 *      (sdd/candidate-external-reference/spec/public-api)
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Participant\ExternalReference;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Tests\Helpers\ExternalReferenceCases;

/**
 * @return array{org: Organization, key: string}
 */
function cierOrgWithKey(ApiKeyMode $mode): array
{
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate($mode);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'mode' => $mode,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);

    return ['org' => $org, 'key' => $rawKey];
}

function cierProject(Organization $org): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org): Project {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => 'active',
        ]);
    });
}

/**
 * @param  array<string, mixed>  $candidate  merged over the base candidate fields
 * @return array<string, mixed>
 */
function cierPayload(Project $project, array $candidate = [], string $suffix = 'a'): array
{
    return [
        'project_id' => PublicId::encode($project),
        'candidate' => array_merge([
            'candidate_ref' => "cier-{$suffix}",
            'email' => "cier-{$suffix}@example.com",
            'display_name' => 'Mario Rossi',
        ], $candidate),
    ];
}

/**
 * The stored row for a candidate_ref, read straight from the table.
 */
function cierStored(Organization $org, string $candidateRef): Participant
{
    return Participant::query()
        ->where('organization_id', $org->id)
        ->where('candidate_ref', $candidateRef)
        ->firstOrFail();
}

// ---------------------------------------------------------------------------
// Accepted: the four shapes, in live and test mode
// ---------------------------------------------------------------------------

test('POST /v1/interviews stores the reference and echoes exactly what was sent', function (ApiKeyMode $mode, ?int $externalId, ?string $source): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey($mode);
    $project = cierProject($org);

    $candidate = array_filter(['external_id' => $externalId, 'source' => $source], fn (mixed $v): bool => $v !== null);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload($project, $candidate));

    $response->assertCreated();
    $response->assertJsonPath('interview.livemode', $mode === ApiKeyMode::Live);
    expect($response->json('interview'))->toHaveKeys(['external_id', 'source']);
    expect($response->json('interview.external_id'))->toBe($externalId);
    expect($response->json('interview.source'))->toBe($source);

    $stored = cierStored($org, 'cier-a');
    expect($stored->external_id)->toBe($externalId);
    expect($stored->source)->toBe($source);

    $this->assertMatchesContract($response, 'POST', '/interviews');
})->with(['live' => [ApiKeyMode::Live], 'test' => [ApiKeyMode::Test]])
    ->with(fn () => ExternalReferenceCases::validCombinations());

test('the created interview carries the reference as a JSON number and a JSON string', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), [
            'external_id' => ExternalReference::MAX_EXTERNAL_ID,
            'source' => 'acme-ats',
        ]));

    $response->assertCreated();
    // The raw body, because a decoded integer would hide a quoted number.
    expect($response->getContent())->toContain('"external_id":9007199254740991');
    expect($response->getContent())->toContain('"source":"acme-ats"');
});

test('the reference read back by GET matches what create stored', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $created = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['external_id' => 4471, 'source' => 'acme-ats']));
    $created->assertCreated();

    $read = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.$created->json('interview.id'));

    $read->assertOk();
    expect($read->json('external_id'))->toBe(4471);
    expect($read->json('source'))->toBe('acme-ats');
});

test('an explicit null is accepted and stored as null', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['external_id' => null, 'source' => null]));

    $response->assertCreated();
    expect($response->json('interview.external_id'))->toBeNull();
    expect($response->json('interview.source'))->toBeNull();
});

test('the boundary values are accepted: 1, 2^53-1, and a 180-character source, single-byte and multibyte', function (int $externalId, string $source): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['external_id' => $externalId, 'source' => $source]));

    $response->assertCreated();
    expect($response->json('interview.external_id'))->toBe($externalId);
    expect($response->json('interview.source'))->toBe($source);
    expect(cierStored($org, 'cier-a')->source)->toBe($source);
})->with([
    'lowest id, single-byte source' => [1, str_repeat('a', ExternalReference::SOURCE_MAX_LENGTH)],
    'highest id, multibyte source' => [ExternalReference::MAX_EXTERNAL_ID, str_repeat('é', ExternalReference::SOURCE_MAX_LENGTH)],
]);

test('an empty or whitespace-only source is stored as null', function (string $source): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['external_id' => 4471, 'source' => $source]));

    $response->assertCreated();
    expect($response->json('interview.source'))->toBeNull();
    expect($response->json('interview.external_id'))->toBe(4471);
    expect(cierStored($org, 'cier-a')->source)->toBeNull();
})->with(['empty' => [''], 'spaces' => ['   '], 'tab and spaces' => ["\t  "]]);

test('a source is stored trimmed', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['source' => '  acme-ats  ']));

    $response->assertCreated();
    expect($response->json('interview.source'))->toBe('acme-ats');
});

// ---------------------------------------------------------------------------
// Refused: the shared invalid dataset, named on the nested field
// ---------------------------------------------------------------------------

test('an invalid reference is a 422 problem naming the nested field, and enrols nobody', function (string $field, mixed $value): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);
    $project = cierProject($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload($project, [$field => $value]));

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain("candidate.{$field}");
    expect(Participant::query()->where('organization_id', $org->id)->count())->toBe(0);

    $this->assertProblemMatchesContract($response, 422);
})->with(fn () => ExternalReferenceCases::invalid());

test('further invalid external ids are refused: the next value past the cap and a non-numeric string', function (mixed $value): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['external_id' => $value]));

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain('candidate.external_id');
})->with([
    'well past the cap' => [9007199254741992],
    'non-numeric string' => ['abc'],
]);

test('a float with a zero fraction on the wire is refused, not coerced to the integer it resembles', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    // `postJson()` would drop the fraction (`json_encode(12.0)` is `12`), so
    // the body is encoded by hand: a client that writes `12.0` must be refused.
    $body = json_encode(cierPayload(cierProject($org), ['external_id' => 12.0]), JSON_PRESERVE_ZERO_FRACTION);
    expect($body)->toContain('"external_id":12.0');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->call(
        'POST',
        '/api/v1/interviews',
        [],
        [],
        [],
        $this->transformHeadersToServerVars(['CONTENT_TYPE' => 'application/json', 'Accept' => 'application/json']),
        $body,
    );

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain('candidate.external_id');
    expect(Participant::query()->where('organization_id', $org->id)->count())->toBe(0);
});

test('the machine code of each refusal is the violated rule, the same mapping every candidate field uses', function (mixed $value, string $code): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['external_id' => $value]));

    $response->assertStatus(422);
    $codes = collect($response->json('errors'))->where('field', 'candidate.external_id')->pluck('code')->all();
    expect($codes)->toContain($code);
})->with([
    'a string is not an integer' => ['abc', 'integer'],
    'zero is below the minimum' => [0, 'min'],
    'past 2^53-1 is above the maximum' => [ExternalReference::MAX_EXTERNAL_ID + 1, 'max'],
]);

test('an over-long source answers the max rule on candidate.source', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['source' => str_repeat('a', ExternalReference::SOURCE_MAX_LENGTH + 1)]));

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->where('field', 'candidate.source')->pluck('code')->all())->toContain('max');
});

test('a source that is not a string is refused', function (mixed $value): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload(cierProject($org), ['source' => $value]));

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain('candidate.source');
})->with(['an integer' => [42], 'an array' => [['a']], 'a boolean' => [true]]);

// ---------------------------------------------------------------------------
// Neither field is a uniqueness key
// ---------------------------------------------------------------------------

test('the same source and external_id on two enrolments of one project is not a duplicate', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);
    $project = cierProject($org);
    $reference = ['external_id' => 4471, 'source' => 'acme-ats'];

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload($project, $reference, 'one'))
        ->assertCreated();

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload($project, $reference, 'two'))
        ->assertCreated();

    expect(Participant::query()->where('organization_id', $org->id)->where('external_id', 4471)->count())->toBe(2);
});

test('a duplicate email is still a 409 whatever the reference says', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);
    $project = cierProject($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload($project, ['external_id' => 1], 'one'))
        ->assertCreated();

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', cierPayload($project, ['external_id' => 2, 'email' => 'cier-one@example.com'], 'two'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'duplicate_enrolment');
});

// ---------------------------------------------------------------------------
// Idempotency: the reference is part of the request body
// ---------------------------------------------------------------------------

test('an Idempotency-Key replay with the same reference returns the original body', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);
    $payload = cierPayload(cierProject($org), ['external_id' => 4471, 'source' => 'acme-ats']);
    $headers = ['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'cier-replay-1'];

    $first = $this->withHeaders($headers)->postJson('/api/v1/interviews', $payload);
    $first->assertCreated();

    $second = $this->withHeaders($headers)->postJson('/api/v1/interviews', $payload);

    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($second->json())->toBe($first->json());
    expect($second->json('interview.external_id'))->toBe(4471);
});

test('the same Idempotency-Key with a body differing only in external_id is a 409 idempotency_key_reused', function (): void {
    ['org' => $org, 'key' => $rawKey] = cierOrgWithKey(ApiKeyMode::Live);
    $project = cierProject($org);
    $headers = ['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'cier-reuse-1'];

    $this->withHeaders($headers)
        ->postJson('/api/v1/interviews', cierPayload($project, ['external_id' => 4471, 'source' => 'acme-ats']))
        ->assertCreated();

    $this->withHeaders($headers)
        ->postJson('/api/v1/interviews', cierPayload($project, ['external_id' => 4472, 'source' => 'acme-ats']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reused');

    expect(Participant::query()->where('organization_id', $org->id)->count())->toBe(1);
    expect(cierStored($org, 'cier-a')->external_id)->toBe(4471);
});
