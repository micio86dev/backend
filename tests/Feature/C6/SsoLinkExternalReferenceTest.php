<?php

declare(strict_types=1);

/**
 * POST /api/m2m/sso-link carries the optional external reference
 * (candidate-external-reference, slice A2).
 *
 * The `typ:sso-link` JWT carries `external_id` (a JSON integer) and/or
 * `source` only when the value is present after normalisation; an absent value
 * produces no claim key at all, so a link minted without a reference carries
 * exactly the claims it carried before this change. The response body stays
 * `{ token }` (SsoLinkResponseGoldenTest pins that separately).
 *
 * The claims are readable by whoever holds the link, like the existing
 * `email` / `display_name` claims; that visibility is accepted and documented
 * on the controller, not asserted away here.
 *
 * REQ: The sso-link Token Carries The External Reference Only When Present,
 *      External Reference Validation Is One Shared Contract
 *      (sdd/candidate-external-reference/spec/participant-sso)
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\ExternalReferenceCases;

/**
 * @return array{client: ApiClient, key: string}
 */
function ssoLinkRefClient(Organization $org): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'abilities' => ['sso_link:generate'],
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

function ssoLinkRefProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ]);

    makeProjectInterviewable($project);

    return $project;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function ssoLinkRefBody(Project $project, array $extra = []): array
{
    return array_merge([
        'project_id' => $project->id,
        'candidate_ref' => 'ref-cand-001',
        'display_name' => 'Reference Candidate',
        'email' => uniqid('cand-').'@example.test',
        'role_code' => 'ICO',
        'lang' => 'en',
    ], $extra);
}

/**
 * The claims the token itself carries, read straight from its payload segment.
 * Not through `JWTAuth::getPayload()`: decoding feeds the claims into tymon's
 * singleton factory, so a second decode in one test would report claims that
 * belong to the FIRST token.
 *
 * @return array<string, mixed>
 */
function ssoLinkRefClaims(string $token): array
{
    $segment = explode('.', $token)[1];
    $json = base64_decode(strtr($segment, '-_', '+/'), true);

    return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------
// Claims only when present
// ---------------------------------------------------------------------------

test('the token carries external_id and source claims exactly when they were sent', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $body = ssoLinkRefBody($project);
    if ($externalId !== null) {
        $body['external_id'] = $externalId;
    }
    if ($source !== null) {
        $body['source'] = $source;
    }

    $response = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', $body);

    $response->assertStatus(201);
    $claims = ssoLinkRefClaims($response->json('token'));

    if ($externalId === null) {
        expect($claims)->not->toHaveKey('external_id');
    } else {
        // A JSON integer, never the string "4471".
        expect($claims['external_id'])->toBeInt()->toBe($externalId);
    }

    if ($source === null) {
        expect($claims)->not->toHaveKey('source');
    } else {
        expect($claims['source'])->toBe($source);
    }
})->with(fn () => ExternalReferenceCases::validCombinations());

test('a request without the reference mints exactly the claim set it minted before this change', function (): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $response = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', ssoLinkRefBody($project));

    $response->assertStatus(201);
    $keys = array_keys(ssoLinkRefClaims($response->json('token')));
    sort($keys);

    expect($keys)->toBe([
        'candidate_ref', 'display_name', 'email', 'exp', 'iat', 'iss',
        'jti', 'lang', 'nbf', 'org_id', 'project_id', 'role_code', 'sub', 'typ',
    ]);
});

test('an empty or whitespace-only source mints no source claim', function (string $blank): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $response = $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, ['source' => $blank]),
    );

    $response->assertStatus(201);
    expect(ssoLinkRefClaims($response->json('token')))->not->toHaveKey('source');
})->with(['empty' => [''], 'whitespace' => ['   ']]);

test('an explicit null for either field mints no claim', function (): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $response = $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, ['external_id' => null, 'source' => null]),
    );

    $response->assertStatus(201);
    $claims = ssoLinkRefClaims($response->json('token'));
    expect($claims)->not->toHaveKey('external_id')->and($claims)->not->toHaveKey('source');
});

test('the boundary values are accepted: external_id 1 and 2^53-1, a 180-character source', function (): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    foreach ([1, 9007199254740991] as $externalId) {
        $response = $this->withToken($m2m['key'])->postJson(
            '/api/m2m/sso-link',
            ssoLinkRefBody($project, ['external_id' => $externalId]),
        );

        $response->assertStatus(201);
        expect(ssoLinkRefClaims($response->json('token'))['external_id'])->toBe($externalId);
    }

    $source = str_repeat('é', 180);
    $response = $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, ['source' => $source]),
    );

    $response->assertStatus(201);
    expect(ssoLinkRefClaims($response->json('token'))['source'])->toBe($source);
});

// ---------------------------------------------------------------------------
// Refusals
// ---------------------------------------------------------------------------

test('an invalid reference is refused with 422 naming the field and mints nothing', function (string $field, mixed $value): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $response = $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, [$field => $value]),
    );

    $response->assertStatus(422)->assertJsonValidationErrors([$field]);
    expect($response->json())->not->toHaveKey('token');
})->with(fn () => ExternalReferenceCases::invalid());

test('a completed participant refuses the mint with 409 and leaves the stored reference unchanged', function (): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $participant = Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create([
        'candidate_ref' => 'ref-cand-done',
    ]);
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'completato']);

    $response = $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, [
            'candidate_ref' => 'ref-cand-done',
            'external_id' => 9000,
            'source' => 'other-ats',
        ]),
    );

    $response->assertStatus(409);
    expect($response->json())->not->toHaveKey('token');

    $stored = DB::table('participants')->where('id', $participant->id)->first();
    expect($stored->external_id)->toBe(4471)->and($stored->source)->toBe('acme-ats');
});

test('a failed participant refuses the mint with 409 and leaves the stored reference unchanged', function (): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $participant = Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create([
        'candidate_ref' => 'ref-cand-failed',
    ]);
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'errore']);

    $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, [
            'candidate_ref' => 'ref-cand-failed',
            'external_id' => 9000,
            'source' => 'other-ats',
        ]),
    )->assertStatus(409);

    $stored = DB::table('participants')->where('id', $participant->id)->first();
    expect($stored->external_id)->toBe(4471)->and($stored->source)->toBe('acme-ats');
});

test('a mint without the reference carries no claim even right after a mint that had one', function (): void {
    // tymon's factory is a container singleton that accumulates custom claims
    // across make() calls. A link minted for candidate B must never inherit the
    // external reference of candidate A minted a moment earlier in the same
    // process (the scheduled-invitation sweep mints many links in one run).
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $first = $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, ['candidate_ref' => 'ref-cand-a', 'external_id' => 4471, 'source' => 'acme-ats']),
    );
    $second = $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, ['candidate_ref' => 'ref-cand-b']),
    );

    expect(ssoLinkRefClaims($first->json('token')))->toHaveKeys(['external_id', 'source']);
    $claims = ssoLinkRefClaims($second->json('token'));
    expect($claims)->not->toHaveKey('external_id')->and($claims)->not->toHaveKey('source');
    expect($claims['candidate_ref'])->toBe('ref-cand-b');
});

test('minting writes no participant row: the exchange does', function (): void {
    $org = Organization::factory()->create();
    $project = ssoLinkRefProject($org);
    $m2m = ssoLinkRefClient($org);

    $this->withToken($m2m['key'])->postJson(
        '/api/m2m/sso-link',
        ssoLinkRefBody($project, ['external_id' => 4471, 'source' => 'acme-ats']),
    )->assertStatus(201);

    expect(Participant::where('project_id', $project->id)->count())->toBe(0);
});
