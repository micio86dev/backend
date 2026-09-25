<?php

declare(strict_types=1);

/**
 * `GET /api/embed/exchange?token=` — BEAI Public API session-token exchange
 * (public-api step 5, SPEC.md §3.5, G-32). T-TOK-001..009.
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\InterviewEvent;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Tests\Helpers\PublicApi\ThrowingJwtAuth;
use Tymon\JWTAuth\JWTAuth;

/**
 * @return array{org: Organization, key: string, id: string, token: string, participant: Participant}
 */
function exgCreateInterview(): array
{
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);

    $project = TenantContextScope::runFor($org->id, function () use ($org): Project {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => 'active',
        ]);
    });

    $response = test()->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', [
            'project_id' => PublicId::encode($project),
            'candidate' => [
                'candidate_ref' => 'ref-'.uniqid(),
                'email' => uniqid().'@example.com',
                'display_name' => 'Candidate',
            ],
        ])
        ->assertCreated();

    $id = $response->json('interview.id');
    $bareId = PublicId::decode($id, Participant::publicIdPrefix());
    $participant = Participant::wherePublicId($bareId)->firstOrFail();

    return [
        'org' => $org,
        'key' => $rawKey,
        'id' => $id,
        'token' => $response->json('session_token'),
        'participant' => $participant,
    ];
}

function exgForeignToken(array $claims): string
{
    $secret = config()->string('public_api.session_secret');
    $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText($secret));
    $now = new DateTimeImmutable;

    $builder = $config->builder()
        ->issuedBy($claims['iss'] ?? 'beai')
        ->permittedFor($claims['aud'] ?? 'embed')
        ->relatedTo($claims['sub'] ?? 'int_01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->identifiedBy($claims['jti'] ?? 'a-jti')
        ->issuedAt($claims['iat'] ?? $now)
        ->expiresAt($claims['exp'] ?? $now->modify('+15 minutes'))
        ->withClaim('org', $claims['org'] ?? 'org_01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->withClaim('mode', $claims['mode'] ?? 'live');

    return $builder->getToken($config->signer(), $config->signingKey())->toString();
}

// ─── T-TOK-001: expiry ────────────────────────────────────────────────────────

test('T-TOK-001: an expired session token → 401 token_invalid', function (): void {
    ['participant' => $participant] = exgCreateInterview();

    $now = new DateTimeImmutable('-1 hour');
    // The participant's REAL organization (step 5 review follow-up, item 3)
    // — an otherwise-complete token, so `isExpired()` is what actually
    // decides this test, not an incidental 401 from the placeholder org in
    // `exgForeignToken()`'s own default never resolving to a real row.
    $expired = exgForeignToken([
        'sub' => PublicId::encode($participant),
        'org' => PublicId::encode($participant->organization),
        'jti' => 'expired-jti',
        'iat' => $now,
        'exp' => $now->modify('+15 minutes'),
    ]);

    $response = $this->getJson('/api/embed/exchange?token='.$expired);

    $response->assertStatus(401)->assertJsonPath('code', 'token_invalid');
});

// ─── T-TOK-002: single use ────────────────────────────────────────────────────

test('T-TOK-002: a second exchange of the same token → 410 token_consumed', function (): void {
    $exchange = exgCreateInterview();

    $this->getJson('/api/embed/exchange?token='.$exchange['token'])->assertOk();

    $second = $this->getJson('/api/embed/exchange?token='.$exchange['token']);
    $second->assertStatus(410)->assertJsonPath('code', 'token_consumed');
});

// ─── step 5 review follow-up, item 2: missing/empty/array-shaped ?token= ─────

test('a missing ?token= query parameter → 401 token_invalid, never 500', function (): void {
    $response = $this->getJson('/api/embed/exchange');

    $response->assertStatus(401)->assertJsonPath('code', 'token_invalid');
});

test('an empty ?token= query parameter → 401 token_invalid, never 500', function (): void {
    $response = $this->getJson('/api/embed/exchange?token=');

    $response->assertStatus(401)->assertJsonPath('code', 'token_invalid');
});

test('an array-shaped ?token[]= query parameter → 401 token_invalid, never 500', function (): void {
    $response = $this->getJson('/api/embed/exchange?token[]=a&token[]=b');

    $response->assertStatus(401)->assertJsonPath('code', 'token_invalid');
});

// ─── step 5 review follow-up, item 1: mint failure rolls back the consume ────

test('a candidate JWT mint failure after consume rolls back — 500, token still set, no event row', function (): void {
    $exchange = exgCreateInterview();
    $originalJti = $exchange['participant']->fresh()->session_token_jti;
    expect($originalJti)->not->toBeNull();

    app()->instance(JWTAuth::class, new ThrowingJwtAuth);

    $response = $this->getJson('/api/embed/exchange?token='.$exchange['token']);

    $response->assertStatus(500);

    $participant = Participant::find($exchange['participant']->id);
    expect($participant->session_token_jti)->toBe($originalJti);
    expect($participant->status)->toBe('in_attesa');

    $events = InterviewEvent::where('participant_id', $participant->id)->pluck('type')->all();
    expect($events)->not->toContain('token_consumed');
});

// ─── T-TOK-004: wrong audience ────────────────────────────────────────────────

test('T-TOK-004: a token with the wrong audience → 401 token_invalid', function (): void {
    ['participant' => $participant] = exgCreateInterview();

    // Otherwise-complete token, real organization (step 5 review follow-up,
    // item 3) — `aud` is the ONLY thing wrong with it, so it is genuinely
    // the audience check deciding this test, not an incidental 401 from an
    // org claim that was never going to resolve either way.
    $wrongAudience = exgForeignToken([
        'sub' => PublicId::encode($participant),
        'org' => PublicId::encode($participant->organization),
        'aud' => 'not-embed',
    ]);

    $this->getJson('/api/embed/exchange?token='.$wrongAudience)
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_invalid');
});

// ─── T-TOK-005: wrong signature ───────────────────────────────────────────────

test('T-TOK-005: a token signed with the wrong secret → 401 token_invalid', function (): void {
    ['participant' => $participant] = exgCreateInterview();

    $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText('a-totally-different-secret-that-is-long-enough-for-hs256'));
    $now = new DateTimeImmutable;
    $bad = $config->builder()
        ->issuedBy('beai')
        ->permittedFor('embed')
        ->relatedTo(PublicId::encode($participant))
        ->identifiedBy('bad-jti')
        ->issuedAt($now)
        ->expiresAt($now->modify('+15 minutes'))
        ->getToken($config->signer(), $config->signingKey())
        ->toString();

    $this->getJson('/api/embed/exchange?token='.$bad)
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_invalid');
});

// ─── T-TOK-006: interview no longer pending ──────────────────────────────────

test('T-TOK-006: a token for an interview no longer pending → 410 token_consumed', function (): void {
    $exchange = exgCreateInterview();

    Participant::where('id', $exchange['participant']->id)->update(['status' => 'in_corso']);

    $this->getJson('/api/embed/exchange?token='.$exchange['token'])
        ->assertStatus(410)
        ->assertJsonPath('code', 'token_consumed');
});

// ─── T-TOK-007: a session token cannot authenticate on /v1 ───────────────────

test('T-TOK-007: a session token presented as a Bearer key on /v1/organization → 401 invalid_api_key', function (): void {
    $exchange = exgCreateInterview();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$exchange['token']])
        ->getJson('/api/v1/organization');

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_api_key');
});

// ─── T-TOK-008: no Set-Cookie ─────────────────────────────────────────────────

test('T-TOK-008: exchange never sets a cookie (G-32)', function (): void {
    $exchange = exgCreateInterview();

    $response = $this->getJson('/api/embed/exchange?token='.$exchange['token']);

    $response->assertOk();
    expect($response->headers->has('Set-Cookie'))->toBeFalse();
    expect($response->headers->get('Set-Cookie'))->toBeNull();
});

// ─── T-TOK-009: records token_consumed, status stays pending ─────────────────

test('T-TOK-009: exchange records token_consumed and leaves status pending', function (): void {
    $exchange = exgCreateInterview();

    $this->getJson('/api/embed/exchange?token='.$exchange['token'])->assertOk();

    $participant = Participant::find($exchange['participant']->id);
    expect($participant->status)->toBe('in_attesa');
    expect($participant->session_token_jti)->toBeNull();

    $events = InterviewEvent::where('participant_id', $participant->id)->pluck('type')->all();
    expect($events)->toContain('token_consumed');
});

// ─── gga finding 1: org claim must match the participant's real organization ─

test('a token whose org claim belongs to a DIFFERENT organization → 401 token_invalid', function (): void {
    ['participant' => $participant] = exgCreateInterview();

    $otherOrg = Organization::factory()->create();

    $mismatchedOrg = exgForeignToken([
        'sub' => PublicId::encode($participant),
        'org' => PublicId::encode($otherOrg),
    ]);

    $response = $this->getJson('/api/embed/exchange?token='.$mismatchedOrg);

    $response->assertStatus(401)->assertJsonPath('code', 'token_invalid');

    // The real participant's session_token_jti is untouched — the mismatch
    // must be refused, never partially consumed.
    $participant->refresh();
    expect($participant->session_token_jti)->not->toBeNull();
});

test('a malformed (non-org-prefixed) org claim → 401 token_invalid', function (): void {
    ['participant' => $participant] = exgCreateInterview();

    $malformedOrg = exgForeignToken([
        'sub' => PublicId::encode($participant),
        'org' => 'not-an-org-id',
    ]);

    $this->getJson('/api/embed/exchange?token='.$malformedOrg)
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_invalid');
});

// ─── gga finding 1: the consume UPDATE is atomic with the status check ───────

test('a status change between the read and the consume UPDATE loses the race → 410 token_consumed, no token minted', function (): void {
    $exchange = exgCreateInterview();
    $participantId = $exchange['participant']->id;
    $originalJti = $exchange['participant']->fresh()->session_token_jti;
    expect($originalJti)->not->toBeNull();

    // Simulates a concurrent status transition landing between this
    // controller's participant lookup and its compare-and-clear UPDATE: the
    // FIRST query DB::listen sees after that point (the exchange's own
    // lookup) triggers a raw status flip on the SAME row, before control
    // ever reaches the UPDATE statement.
    $flipped = false;
    DB::listen(function ($query) use (&$flipped, $participantId): void {
        if ($flipped) {
            return;
        }

        if (str_contains($query->sql, 'select') && str_contains($query->sql, 'from "participants"') && str_contains($query->sql, 'public_id')) {
            $flipped = true;
            DB::table('participants')->where('id', $participantId)->update(['status' => 'in_corso']);
        }
    });

    $response = $this->getJson('/api/embed/exchange?token='.$exchange['token']);

    $response->assertStatus(410)->assertJsonPath('code', 'token_consumed');
    expect($response->json())->not->toHaveKey('access_token');

    // The race must not have handed out a candidate JWT nor left the row
    // half-consumed (step 5 review follow-up, item 4: session_token_jti is
    // untouched too, not only status — the 0-row compare-and-clear must not
    // have cleared it).
    $participant = Participant::find($participantId);
    expect($participant->status)->toBe('in_corso');
    expect($participant->session_token_jti)->toBe($originalJti);
});

// ─── T-TOK-010: the minted candidate JWT is a real, usable session ───────────

test('T-TOK-010: the access_token from exchange authenticates GET /api/candidate/session', function (): void {
    $exchange = exgCreateInterview();

    $accessToken = $this->getJson('/api/embed/exchange?token='.$exchange['token'])
        ->assertOk()
        ->json('access_token');

    $this->withHeaders(['Authorization' => 'Bearer '.$accessToken])
        ->getJson('/api/candidate/session')
        ->assertOk();
});
