<?php

declare(strict_types=1);

/**
 * `GET /api/embed/frame-policy?token=` — read-only `allowed_domains` lookup
 * for the embed page's `Content-Security-Policy: frame-ancestors` header
 * (public-api step 10, SPEC.md §4.4).
 *
 * Deliberately a SEPARATE action from `exchange()` (see
 * `ExchangeController::framePolicy()`'s own docblock): this endpoint must
 * NEVER consume the session token, so every test here that ALSO calls
 * `/embed/exchange` afterwards asserts that call still succeeds — the
 * single-use token must still be spendable after any number of frame-policy
 * lookups.
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

/**
 * @return array{org: Organization, token: string, participant: Participant}
 */
function fpCreateInterview(array $orgOverrides = []): array
{
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $org = Organization::factory()->create($orgOverrides);
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
        'org' => $org->fresh(),
        'token' => $response->json('session_token'),
        'participant' => $participant,
    ];
}

function fpForeignToken(array $claims): string
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

test('a valid session token resolves the organization\'s allowed_domains', function (): void {
    ['token' => $token] = fpCreateInterview(['allowed_domains' => ['acme.example', 'hr.acme.example']]);

    $response = test()->getJson('/api/embed/frame-policy?token='.$token);

    $response->assertOk()
        ->assertExactJson(['allowed_domains' => ['acme.example', 'hr.acme.example']]);
});

test('allowed_domains renders [] when the organization configured none', function (): void {
    ['token' => $token] = fpCreateInterview(['allowed_domains' => null]);

    $response = test()->getJson('/api/embed/frame-policy?token='.$token);

    $response->assertOk()->assertExactJson(['allowed_domains' => []]);
});

test('a missing token → 401 token_invalid', function (): void {
    test()->getJson('/api/embed/frame-policy')
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_invalid');
});

test('an expired token → 401 token_invalid', function (): void {
    ['participant' => $participant] = fpCreateInterview();

    $now = new DateTimeImmutable('-1 hour');
    $orgClaim = PublicId::encode($participant->organization);
    $token = fpForeignToken([
        'sub' => PublicId::encode($participant),
        'org' => $orgClaim,
        'iat' => $now,
        'exp' => $now->modify('+15 minutes'),
    ]);

    test()->getJson('/api/embed/frame-policy?token='.$token)
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_invalid');
});

test('a mis-signed token → 401 token_invalid', function (): void {
    ['participant' => $participant] = fpCreateInterview();

    $config = Configuration::forSymmetricSigner(
        new Sha256,
        InMemory::plainText('a-completely-different-secret-at-least-32-bytes-long')
    );
    $now = new DateTimeImmutable;
    $token = $config->builder()
        ->issuedBy('beai')
        ->permittedFor('embed')
        ->relatedTo(PublicId::encode($participant))
        ->identifiedBy('a-jti')
        ->issuedAt($now)
        ->expiresAt($now->modify('+15 minutes'))
        ->withClaim('org', PublicId::encode($participant->organization))
        ->withClaim('mode', 'live')
        ->getToken($config->signer(), $config->signingKey())
        ->toString();

    test()->getJson('/api/embed/frame-policy?token='.$token)
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_invalid');
});

test('an org claim that does not decode to a real organization → 401 token_invalid', function (): void {
    ['participant' => $participant] = fpCreateInterview();

    $token = fpForeignToken([
        'sub' => PublicId::encode($participant),
        'org' => 'org_01ARZ3NDEKTSV4RRFFQ69G5FAV', // well-formed, resolves to no row
    ]);

    test()->getJson('/api/embed/frame-policy?token='.$token)
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_invalid');
});

test('a frame-policy lookup never consumes the single-use session token — /embed/exchange still succeeds after it', function (): void {
    ['token' => $token, 'participant' => $participant] = fpCreateInterview();
    $originalJti = $participant->fresh()->session_token_jti;

    test()->getJson('/api/embed/frame-policy?token='.$token)->assertOk();

    expect($participant->fresh()->session_token_jti)->toBe($originalJti);

    test()->getJson('/api/embed/exchange?token='.$token)
        ->assertOk()
        ->assertJsonStructure(['access_token']);
});

test('repeated frame-policy lookups against the same token are all 200 — no throttle-adjacent write side effect', function (): void {
    ['token' => $token] = fpCreateInterview(['allowed_domains' => ['acme.example']]);

    test()->getJson('/api/embed/frame-policy?token='.$token)->assertOk();
    test()->getJson('/api/embed/frame-policy?token='.$token)->assertOk();
    test()->getJson('/api/embed/frame-policy?token='.$token)
        ->assertOk()
        ->assertExactJson(['allowed_domains' => ['acme.example']]);
});
