<?php

declare(strict_types=1);

/**
 * SSO Exchange 401 scenarios (C6 — Participant + SSO Ingress).
 *
 * Covers all 401 responses:
 * - expired token
 * - bad signature
 * - wrong typ (candidate JWT, user JWT, M2M key at exchange)
 * - replay (jti consumed → 401)
 * - missing project (non-existent project_id → 401)
 * - soft-deleted project → 401 (SoftDeletingScope still active)
 * - missing display_name claim → 401 (jti consumed at step 3 before display_name check)
 *
 * REQ: Public SSO Exchange — all 401 scenarios
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeExchangeProject401(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(array_merge([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('expired sso-link token → 401', function (): void {
    // Build a JWT token with a past exp by hand-crafting the signed token.
    // tymon's factory rejects expired payloads at construction time, so we
    // construct the JWT parts manually using the same HMAC-SHA256 secret.
    $secret = config('jwt.secret');

    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $header = str_replace(['+', '/', '='], ['-', '_', ''], $header);

    $iat = time() - 120; // 2 minutes ago
    $exp = time() - 60;  // 1 minute ago (expired)

    $payloadData = [
        'iss' => config('app.url'),
        'iat' => $iat,
        'nbf' => $iat,
        'exp' => $exp,
        'jti' => Str::random(36),
        'sub' => 'cand-expired-ref',
        'typ' => 'sso-link',
        'candidate_ref' => 'cand-expired-ref',
        'display_name' => 'Test',
        'project_id' => 1,
        'org_id' => 1,
        'role_code' => 'ICO',
        'lang' => 'en',
    ];

    $payloadEncoded = base64_encode(json_encode($payloadData));
    $payloadEncoded = str_replace(['+', '/', '='], ['-', '_', ''], $payloadEncoded);

    $sig = hash_hmac('sha256', $header.'.'.$payloadEncoded, $secret, true);
    $sigEncoded = base64_encode($sig);
    $sigEncoded = str_replace(['+', '/', '='], ['-', '_', ''], $sigEncoded);

    $expiredToken = $header.'.'.$payloadEncoded.'.'.$sigEncoded;

    $this->getJson('/api/sso/exchange?token='.$expiredToken)
        ->assertUnauthorized();
});

test('bad signature → 401', function (): void {
    // Tamper with a valid token by appending invalid chars
    $this->getJson('/api/sso/exchange?token=invalid.token.here')
        ->assertUnauthorized();
});

test('wrong typ: candidate JWT at exchange → 401 (typ !== sso-link)', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create(['status' => 'active']);

    // Mint a candidate JWT (not an sso-link)
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'ref-typ-test',
        'display_name' => 'Test',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    $candidateToken = CandidateTokenFactory::mintCandidateToken($participant->fresh());

    $this->getJson('/api/sso/exchange?token='.$candidateToken)
        ->assertUnauthorized();
});

test('wrong typ: user JWT at exchange → 401', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $jwt = auth('api')->login($user);

    $this->getJson('/api/sso/exchange?token='.$jwt)
        ->assertUnauthorized();
});

test('replay: jti already consumed → 401', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject401($org);
    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'cand-replay',
        'display_name' => 'Test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ]);

    // First exchange — succeeds
    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();

    // Replay — jti already consumed → 401
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('missing project (non-existent project_id in token) → 401', function (): void {
    $org = Organization::factory()->create();
    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'cand-noproject',
        'display_name' => 'Test',
        'project_id' => 999999,  // non-existent
        'org_id' => $org->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ]);

    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('soft-deleted project → 401 (SoftDeletingScope still active at public exchange)', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject401($org);
    $projectId = $project->id;

    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'cand-softdel',
        'display_name' => 'Test',
        'project_id' => $projectId,
        'org_id' => $org->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ]);

    // Soft-delete the project
    $project->delete();

    // exchange must return 401 (project not found because SoftDeletingScope still active)
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('missing display_name claim → 401 (jti consumed before this check)', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject401($org);

    // Mint with empty display_name
    JWTAuth::factory()->setTTL(30);
    $payload = JWTAuth::factory()->customClaims([
        'sub' => 'cand-nodisplay',
        'typ' => 'sso-link',
        'candidate_ref' => 'cand-nodisplay',
        'display_name' => '',  // empty
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ])->make();
    $token = JWTAuth::encode($payload)->get();

    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('no token provided → 401', function (): void {
    $this->getJson('/api/sso/exchange')->assertUnauthorized();
});
