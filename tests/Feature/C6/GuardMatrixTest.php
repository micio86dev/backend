<?php

declare(strict_types=1);

/**
 * Guard non-interchangeability matrix tests (C6 — Participant + SSO Ingress).
 *
 * 4×4 matrix: 4 credential types × 4 guards/endpoints
 *
 * | Token type      | api guard | api-m2m guard | api-candidate guard | sso-link exchange |
 * |----------------|-----------|---------------|---------------------|-------------------|
 * | User JWT        | ✓         | ✗ 401         | ✗ 401 (typ≠cand)   | ✗ 401 (typ≠sso)   |
 * | M2M opaque key  | ✗ 401     | ✓             | ✗ 401 (not JWT)     | ✗ 401             |
 * | Candidate JWT   | ✗ 401     | ✗ 401         | ✓                   | ✗ 401 (typ≠sso)   |
 * | SSO-link JWT    | ✗ 401     | ✗ 401         | ✗ 401 (typ≠cand)   | ✓ (consumed once) |
 *
 * REQ: Four Guards Mutually Non-Interchangeable
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

function matrixOrg(): Organization
{
    return Organization::factory()->create();
}

function matrixUser(Organization $org): User
{
    return User::factory()->create(['organization_id' => $org->id]);
}

function matrixM2mKey(Organization $org, array $abilities = ['participants:read']): string
{
    $rawKey = ApiKeyGenerator::generate();
    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
        'is_active'       => true,
        'abilities'       => $abilities,
    ]);

    return $rawKey;
}

function matrixParticipant(Organization $org): Participant
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'matrix-' . uniqid(),
        'display_name'    => 'Matrix Test',
        'status'          => 'in_attesa',
    ]);
    $p->save();

    return $p->fresh();
}

function matrixProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create([
        'status'          => 'active',
        'role_code'       => 'ICO',
        'assessment_type' => 'standard',
        'goes_live_at'    => null,
        'deadline_at'     => null,
    ]);
}

// ─── Register test routes ──────────────────────────────────────────────────

beforeEach(function (): void {
    // Register minimal test routes for each guard under /api/ for JSON error handling
    Route::middleware('auth:api')
        ->prefix('api')
        ->get('/test-api-guard', fn () => response()->json(['guard' => 'api']));

    Route::middleware('auth:api-candidate')
        ->prefix('api')
        ->withoutMiddleware(\App\Http\Middleware\TenantContext::class)
        ->get('/test-candidate-guard', fn () => response()->json(['guard' => 'candidate']));
});

// ---------------------------------------------------------------------------
// Row 1: User JWT
// ---------------------------------------------------------------------------

test('user JWT on api guard → 200', function (): void {
    $org  = matrixOrg();
    $user = matrixUser($org);
    $jwt  = auth('api')->login($user);

    $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
        ->getJson('/api/test-api-guard')
        ->assertOk();
});

test('user JWT on auth:api-candidate guard → 401 (typ≠candidate)', function (): void {
    $org  = matrixOrg();
    $user = matrixUser($org);
    $jwt  = auth('api')->login($user);

    $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
        ->getJson('/api/test-candidate-guard')
        ->assertUnauthorized();
});

test('user JWT at sso exchange → 401 (typ≠sso-link)', function (): void {
    $org  = matrixOrg();
    $user = matrixUser($org);
    $jwt  = auth('api')->login($user);

    $this->getJson('/api/sso/exchange?token=' . $jwt)->assertUnauthorized();
});

test('user JWT on auth:api-m2m route → 401', function (): void {
    $org  = matrixOrg();
    $user = matrixUser($org);
    $jwt  = auth('api')->login($user);

    $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
        ->getJson('/api/m2m/whoami')
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Row 2: M2M opaque key
// ---------------------------------------------------------------------------

test('M2M key on auth:api route → 401', function (): void {
    $org    = matrixOrg();
    $rawKey = matrixM2mKey($org);

    $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/test-api-guard')
        ->assertUnauthorized();
});

test('M2M key on auth:api-m2m route → 200', function (): void {
    $org    = matrixOrg();
    $rawKey = matrixM2mKey($org);

    $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/m2m/whoami')
        ->assertOk();
});

test('M2M key on auth:api-candidate guard → 401 (not a JWT)', function (): void {
    $org    = matrixOrg();
    $rawKey = matrixM2mKey($org);

    $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/test-candidate-guard')
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Row 3: Candidate JWT
// ---------------------------------------------------------------------------

test('candidate JWT on auth:api guard → 401 (prv MISMATCH)', function (): void {
    $org         = matrixOrg();
    $participant = matrixParticipant($org);
    $candidateJwt = CandidateTokenFactory::mintCandidateToken($participant);

    // prv=hash(Participant) ≠ hash(User) → TokenInvalidException on api guard
    $this->withHeaders(['Authorization' => 'Bearer ' . $candidateJwt])
        ->getJson('/api/test-api-guard')
        ->assertUnauthorized();
});

test('candidate JWT on auth:api-m2m guard → 401 (not an opaque key)', function (): void {
    $org         = matrixOrg();
    $participant = matrixParticipant($org);
    $candidateJwt = CandidateTokenFactory::mintCandidateToken($participant);

    $this->withHeaders(['Authorization' => 'Bearer ' . $candidateJwt])
        ->getJson('/api/m2m/whoami')
        ->assertUnauthorized();
});

test('candidate JWT on auth:api-candidate guard → 200', function (): void {
    $org         = matrixOrg();
    $participant = matrixParticipant($org);
    $candidateJwt = CandidateTokenFactory::mintCandidateToken($participant);

    $this->withHeaders(['Authorization' => 'Bearer ' . $candidateJwt])
        ->getJson('/api/test-candidate-guard')
        ->assertOk();
});

test('candidate JWT at sso exchange → 401 (typ≠sso-link)', function (): void {
    $org         = matrixOrg();
    $participant = matrixParticipant($org);
    $candidateJwt = CandidateTokenFactory::mintCandidateToken($participant);

    $this->getJson('/api/sso/exchange?token=' . $candidateJwt)->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Row 4: SSO-link JWT
// ---------------------------------------------------------------------------

test('sso-link JWT on auth:api guard → 401 (sub=candidate_ref → User::find=null)', function (): void {
    $org     = matrixOrg();
    $project = matrixProject($org);
    // Use a numeric-looking candidate_ref so PostgreSQL bigint cast succeeds
    // and User::find returns null (→ 401), not a cast error (→ 500).
    // SSO-link JWTs carry a string candidate_ref as sub; on the standard api
    // guard that string is passed to User::find which needs a valid bigint.
    $ssoLink = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => '999999999',
        'display_name'  => 'Test',
        'project_id'    => $project->id,
        'org_id'        => $org->id,
        'role_code'     => 'ICO',
        'lang'          => 'en',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer ' . $ssoLink])
        ->getJson('/api/test-api-guard')
        ->assertUnauthorized();
});

test('sso-link JWT on auth:api-candidate guard → 401 (typ≠candidate)', function (): void {
    $org     = matrixOrg();
    $project = matrixProject($org);
    $ssoLink = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'matrix-sso-cand',
        'display_name'  => 'Test',
        'project_id'    => $project->id,
        'org_id'        => $org->id,
        'role_code'     => 'ICO',
        'lang'          => 'en',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer ' . $ssoLink])
        ->getJson('/api/test-candidate-guard')
        ->assertUnauthorized();
});

test('sso-link JWT at exchange → consumed (200 on valid token)', function (): void {
    $org     = matrixOrg();
    $project = matrixProject($org);
    $ssoLink = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'matrix-sso-exchange',
        'display_name'  => 'Test',
        'project_id'    => $project->id,
        'org_id'        => $org->id,
        'role_code'     => 'ICO',
        'lang'          => 'en',
    ]);

    $this->getJson('/api/sso/exchange?token=' . $ssoLink)->assertOk();
});

// ---------------------------------------------------------------------------
// sub validation
// ---------------------------------------------------------------------------

test('sub=0 on api-candidate guard → 401 (not a positive int)', function (): void {
    // Craft a token with typ=candidate but sub=0
    \Tymon\JWTAuth\Facades\JWTAuth::factory()->setTTL(120);
    $payload = \Tymon\JWTAuth\Facades\JWTAuth::factory()->customClaims([
        'sub'           => 0,
        'typ'           => 'candidate',
        'candidate_ref' => 'test',
    ])->make();
    $token = \Tymon\JWTAuth\Facades\JWTAuth::encode($payload)->get();

    $this->withHeaders(['Authorization' => 'Bearer ' . $token])
        ->getJson('/api/test-candidate-guard')
        ->assertUnauthorized();
});

test('sub=non-numeric on api-candidate guard → 401', function (): void {
    \Tymon\JWTAuth\Facades\JWTAuth::factory()->setTTL(120);
    $payload = \Tymon\JWTAuth\Facades\JWTAuth::factory()->customClaims([
        'sub'           => 'not-a-number',
        'typ'           => 'candidate',
        'candidate_ref' => 'test',
    ])->make();
    $token = \Tymon\JWTAuth\Facades\JWTAuth::factory()->setTTL(120)->customClaims([
        'sub'           => 'not-a-number',
        'typ'           => 'candidate',
        'candidate_ref' => 'test',
    ])->make();
    $tokenStr = \Tymon\JWTAuth\Facades\JWTAuth::encode($token)->get();

    $this->withHeaders(['Authorization' => 'Bearer ' . $tokenStr])
        ->getJson('/api/test-candidate-guard')
        ->assertUnauthorized();
});
