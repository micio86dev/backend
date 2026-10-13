<?php

declare(strict_types=1);

/**
 * `POST /v1/interviews/{id}/session-tokens` — BEAI Public API (public-api
 * step 5), SPEC.md §3.5. T-INT-012, T-INT-013 (cross-org), T-TOK-003 (revokes
 * previous).
 */

use App\Enums\ApiKeyMode;
use App\Http\Controllers\PublicApi\SessionTokenController;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function stkOrgWithScopedKey(): array
{
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);

    return ['org' => $org, 'key' => $rawKey];
}

function stkCreateInterview(Organization $org, string $key, array $overrides = []): array
{
    $project = TenantContextScope::runFor($org->id, function () use ($org): Project {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada '.uniqid(), 'provider' => 'heygen', 'config' => []]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => 'active',
        ]);
    });

    $response = test()->withHeaders(['Authorization' => 'Bearer '.$key])
        ->postJson('/api/v1/interviews', array_replace_recursive([
            'project_id' => PublicId::encode($project),
            'candidate' => [
                'candidate_ref' => 'ref-'.uniqid(),
                'email' => uniqid().'@example.com',
                'display_name' => 'Candidate',
            ],
        ], $overrides))
        ->assertCreated();

    return ['id' => $response->json('interview.id'), 'first_token' => $response->json('session_token')];
}

test('T-INT-012: minting a new token only while pending; every other status → 409 invalid_state', function (): void {
    ['org' => $org, 'key' => $rawKey] = stkOrgWithScopedKey();
    $interview = stkCreateInterview($org, $rawKey);

    $ok = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews/'.$interview['id'].'/session-tokens');

    $ok->assertCreated();
    $ok->assertJsonStructure(['session_token', 'expires_at', 'hosted_url']);
    $this->assertMatchesContract($ok, 'POST', '/interviews/{id}/session-tokens');

    // Force the participant out of pending directly (no /start route needed
    // for this test — only the status guard on the mint endpoint matters).
    DB::table('participants')->where('public_id', PublicId::decode($interview['id'], 'int_'))
        ->update(['status' => 'in_corso']);

    $blocked = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews/'.$interview['id'].'/session-tokens');

    $blocked->assertStatus(409)->assertJsonPath('code', 'invalid_state');
});

test('T-INT-013: cross-org detail and session-token mint both 404', function (): void {
    ['org' => $orgA, 'key' => $rawKeyA] = stkOrgWithScopedKey();
    ['org' => $orgB, 'key' => $rawKeyB] = stkOrgWithScopedKey();

    $interview = stkCreateInterview($orgA, $rawKeyA);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyB])
        ->getJson('/api/v1/interviews/'.$interview['id'])
        ->assertNotFound();

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyB])
        ->postJson('/api/v1/interviews/'.$interview['id'].'/session-tokens')
        ->assertNotFound();
});

test('T-TOK-003: re-minting revokes the previous token (old exchange → 410, new one works)', function (): void {
    ['org' => $org, 'key' => $rawKey] = stkOrgWithScopedKey();
    $interview = stkCreateInterview($org, $rawKey);

    $reMint = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews/'.$interview['id'].'/session-tokens')
        ->assertCreated();

    $newToken = $reMint->json('session_token');
    expect($newToken)->not->toBe($interview['first_token']);

    // Old token → 410 token_consumed (it no longer matches session_token_jti).
    $this->getJson('/api/embed/exchange?token='.$interview['first_token'])
        ->assertStatus(410)
        ->assertJsonPath('code', 'token_consumed');

    // New token → 200.
    $this->getJson('/api/embed/exchange?token='.$newToken)
        ->assertOk()
        ->assertJsonStructure(['access_token']);
});

// ─── gga round 3 finding 1: session-token mint stays allowed for a pending
// participant whose project was later soft-deleted ───────────────────────────
//
// Decision (documented, not "fix"): unlike POST /v1/interviews (create),
// which stays gated on a LIVE, active project (404 for a trashed
// project_id — the calling system cannot start a NEW enrolment against a
// project that no longer exists), minting a session token for an EXISTING
// pending participant has nothing to do with the project's own lifecycle —
// the participant may still complete the interview they were already
// enrolled in, and the embed/hosted page only ever needs the PARTICIPANT
// row (SessionTokenController never reads `project` at all). Gating this on
// `invalid_state` would conflate "the project row is gone" with "the
// interview itself is no longer pending", which are different facts.
test('session-token mint stays allowed (201) for a pending participant whose project was later soft-deleted', function (): void {
    ['org' => $org, 'key' => $rawKey] = stkOrgWithScopedKey();
    $interview = stkCreateInterview($org, $rawKey);

    $participantBareId = PublicId::decode($interview['id'], 'int_');
    $projectId = DB::table('participants')->where('public_id', $participantBareId)->value('project_id');
    TenantContextScope::runFor($org->id, fn () => Project::find($projectId)?->delete());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews/'.$interview['id'].'/session-tokens');

    $response->assertCreated();
    $response->assertJsonStructure(['session_token', 'expires_at', 'hosted_url']);
});

test('G-51: a test key cannot mint a session token for a live interview, and a live key cannot for a test one', function (): void {
    ['org' => $org, 'key' => $liveKey] = stkOrgWithScopedKey();
    $liveInterview = stkCreateInterview($org, $liveKey);

    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->testMode()->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);
    $testInterview = stkCreateInterview($org, $testKey);

    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->postJson('/api/v1/interviews/'.$liveInterview['id'].'/session-tokens')
        ->assertNotFound();

    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->postJson('/api/v1/interviews/'.$testInterview['id'].'/session-tokens')
        ->assertNotFound();

    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->postJson('/api/v1/interviews/'.$testInterview['id'].'/session-tokens')
        ->assertCreated();
});

test('a malformed interview id answers 404 and mints nothing', function (string $id): void {
    ['key' => $rawKey] = stkOrgWithScopedKey();

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews/'.$id.'/session-tokens')
        ->assertNotFound();
})->with(['not a public id' => 'garbage', 'wrong prefix' => 'prj_AAAAAAAAAAAAAAAAAAAAAA']);

test('a well-formed id that matches no interview answers 404', function (): void {
    ['key' => $rawKey] = stkOrgWithScopedKey();
    $unknown = Participant::publicIdPrefix().'01ARZ3NDEKTSV4RRFFQ69G5FAV';

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews/'.$unknown.'/session-tokens')
        ->assertNotFound();
});

test('without a resolved tenant the controller refuses with 404', function (): void {
    app(TenantResolver::class)->setOrgId(null);

    expect(fn () => app(SessionTokenController::class)->store(Request::create('/'), 'int_x'))
        ->toThrow(NotFoundHttpException::class);
});
