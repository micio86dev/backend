<?php

declare(strict_types=1);

/**
 * CandidateTokenFactory unit tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - sso-link JWT carries sub=candidate_ref
 * - sso-link JWT carries claim named role_code (not role)
 * - sso-link JWT has NO Redis write at mint (no 'sso_jti:' key)
 * - candidate JWT TTL is ~120 minutes (not 30 minutes)
 * - candidate JWT carries prv=hash(Participant)
 * - consumeJti returns false on replay
 * - sso-link JWT carries no prv claim
 *
 * REQ: M2M SSO-Link Mint, api-candidate Guard — JWT claim minting/parsing
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Support\Jwt\CandidateTokenFactory;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;

// ---------------------------------------------------------------------------
// sso-link mint
// ---------------------------------------------------------------------------

test('sso-link JWT carries sub=candidate_ref (not a numeric sub)', function (): void {
    $claims = [
        'candidate_ref' => 'ext-abc-123',
        'display_name'  => 'Test User',
        'project_id'    => 1,
        'org_id'        => 1,
        'role_code'     => 'ICO',
        'lang'          => 'en',
    ];

    $token = CandidateTokenFactory::mintSsoLink($claims);

    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('sub'))->toBe('ext-abc-123');
});

test('sso-link JWT carries typ=sso-link', function (): void {
    $claims = [
        'candidate_ref' => 'ref-001',
        'display_name'  => 'Test',
        'project_id'    => 1,
        'org_id'        => 1,
        'role_code'     => 'ICO',
        'lang'          => 'en',
    ];

    $token = CandidateTokenFactory::mintSsoLink($claims);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('typ'))->toBe('sso-link');
});

test('sso-link JWT carries claim named role_code (not role)', function (): void {
    $claims = [
        'candidate_ref' => 'ref-002',
        'display_name'  => 'Test',
        'project_id'    => 1,
        'org_id'        => 1,
        'role_code'     => 'ICO',
        'lang'          => 'en',
    ];

    $token = CandidateTokenFactory::mintSsoLink($claims);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('role_code'))->toBe('ICO');
    expect($payload->get('role'))->toBeNull();
});

test('sso-link JWT has NO prv claim (RAW mint, not fromUser)', function (): void {
    $claims = [
        'candidate_ref' => 'ref-003',
        'display_name'  => 'Test',
        'project_id'    => 1,
        'org_id'        => 1,
        'role_code'     => null,
        'lang'          => 'en',
    ];

    $token = CandidateTokenFactory::mintSsoLink($claims);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('prv'))->toBeNull();
});

test('sso-link mint does NOT write to Redis (no sso_jti: key at mint)', function (): void {
    $claims = [
        'candidate_ref' => 'ref-no-redis',
        'display_name'  => 'Test',
        'project_id'    => 1,
        'org_id'        => 1,
        'role_code'     => 'ICO',
        'lang'          => 'en',
    ];

    $token = CandidateTokenFactory::mintSsoLink($claims);
    $payload = JWTAuth::setToken($token)->getPayload();
    $jti = $payload->get('jti');

    // The sso_jti: key must NOT exist in cache after mint (no pre-storage)
    expect(Cache::has('sso_jti:' . $jti))->toBeFalse();
});

// ---------------------------------------------------------------------------
// candidate JWT mint
// ---------------------------------------------------------------------------

test('candidate JWT carries typ=candidate', function (): void {
    $participant = makeTestParticipant();

    $token = CandidateTokenFactory::mintCandidateToken($participant);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('typ'))->toBe('candidate');
});

test('candidate JWT TTL is ~120 minutes (not the 30-min config default)', function (): void {
    $participant = makeTestParticipant();

    $before = now();
    $token = CandidateTokenFactory::mintCandidateToken($participant);
    $payload = JWTAuth::setToken($token)->getPayload();

    $exp = $payload->get('exp');
    $iat = $payload->get('iat');
    $ttlMinutes = ($exp - $iat) / 60;

    // Should be ~120 minutes (2 hours), not 30 (config default)
    expect($ttlMinutes)->toBeGreaterThanOrEqual(119)
        ->toBeLessThanOrEqual(121);
});

test('candidate JWT carries prv=hash(Participant) (model-bound via fromUser)', function (): void {
    $participant = makeTestParticipant();

    $token = CandidateTokenFactory::mintCandidateToken($participant);
    $payload = JWTAuth::setToken($token)->getPayload();

    $expectedPrv = sha1(Participant::class);

    expect($payload->get('prv'))->toBe($expectedPrv);
});

test('candidate JWT carries role_code claim (not role)', function (): void {
    $participant = makeTestParticipant('ICO');

    $token = CandidateTokenFactory::mintCandidateToken($participant);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('role_code'))->toBe('ICO');
    expect($payload->get('role'))->toBeNull();
});

// ---------------------------------------------------------------------------
// consumeJti — atomic Redis SET NX
// ---------------------------------------------------------------------------

test('consumeJti returns true on first call (first use)', function (): void {
    $result = CandidateTokenFactory::consumeJti('unique-jti-' . uniqid(), 300);

    expect($result)->toBeTrue();
});

test('consumeJti returns false on second call (replay attempt)', function (): void {
    $jti = 'replay-jti-' . uniqid();

    // First consume — succeeds
    $first = CandidateTokenFactory::consumeJti($jti, 300);
    expect($first)->toBeTrue();

    // Second consume — replay → false
    $second = CandidateTokenFactory::consumeJti($jti, 300);
    expect($second)->toBeFalse();
});

test('consumeJti stores key with sso_jti: prefix (not tymon blacklist namespace)', function (): void {
    $jti = 'prefix-test-' . uniqid();
    CandidateTokenFactory::consumeJti($jti, 300);

    // sso_jti: prefix must be present
    expect(Cache::has('sso_jti:' . $jti))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeTestParticipant(string $roleCode = 'ICO'): Participant
{
    $org = Organization::factory()->create();

    $resolver = app(\App\Support\Tenancy\TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = \App\Models\Project::factory()->create(['status' => 'active', 'role_code' => $roleCode]);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'ref-' . uniqid(),
        'display_name'    => 'Test Candidate',
        'role_code'       => $roleCode,
        'language'        => 'en',
        'status'          => 'in_attesa',
    ]);
    $p->save();

    return $p->fresh();
}
