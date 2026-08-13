<?php

declare(strict_types=1);

/**
 * SsoLinkController feature tests (C6 — Participant + SSO Ingress).
 *
 * Covers POST /api/m2m/sso-link:
 * - happy path → 201 + token
 * - missing sso_link:generate ability → 403
 * - past-deadline → 403
 * - display_name absent → 422
 * - role_code mismatch standard → 422
 * - role_code supplied for potential → 422
 * - goes_live_at NULL passes (200/201)
 * - deadline_at NULL passes (200/201)
 * - mint gate status=completato → 409
 * - mint gate status=errore → 409
 * - status=in_attesa → mints normally (201)
 * - cross-org project_id → 404
 * - no Redis write at mint
 *
 * REQ: M2M SSO-Link Mint — all scenarios
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeM2mClient(Organization $org, array $abilities = ['sso_link:generate']): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'abilities' => $abilities,
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

function makeActiveProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(array_merge([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

test('happy path: POST /api/m2m/sso-link → 201 with token', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org);
    $m2m = makeM2mClient($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test Candidate',
            'role_code' => 'ICO',
            'lang' => 'en',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['token']);

    // Token should be a valid JWT with typ=sso-link
    $token = $response->json('token');
    $payload = JWTAuth::setToken($token)->getPayload();
    expect($payload->get('typ'))->toBe('sso-link');
    expect($payload->get('candidate_ref'))->toBe('cand-001');
});

// ---------------------------------------------------------------------------
// Ability gate
// ---------------------------------------------------------------------------

test('missing sso_link:generate ability → 403', function (): void {
    $org = Organization::factory()->create();
    $m2m = makeM2mClient($org, ['participants:read']);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => 1,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test',
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// display_name validation
// ---------------------------------------------------------------------------

test('display_name absent → 422', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org);
    $m2m = makeM2mClient($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-001',
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// role_code validation
// ---------------------------------------------------------------------------

test('role_code mismatch for standard project → 422', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org, ['role_code' => 'ICO']);
    $m2m = makeM2mClient($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test',
            'role_code' => 'FLL', // mismatch
        ])
        ->assertStatus(422);
});

test('role_code supplied for potential project → 422', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org, ['assessment_type' => 'potential', 'role_code' => null]);
    $m2m = makeM2mClient($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test',
            'role_code' => 'ICO', // must not be supplied for potential
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Entry gates
// ---------------------------------------------------------------------------

test('past deadline_at → 403', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org, ['deadline_at' => now()->subHour()]);
    $m2m = makeM2mClient($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test',
        ])
        ->assertStatus(403);
});

test('goes_live_at NULL → project is accessible (mints normally)', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org, ['goes_live_at' => null]);
    $m2m = makeM2mClient($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test',
        ])
        ->assertStatus(201);
});

test('deadline_at NULL → project is accessible (mints normally)', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org, ['deadline_at' => null]);
    $m2m = makeM2mClient($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test',
        ])
        ->assertStatus(201);
});

test('before goes_live_at → 403', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org, ['goes_live_at' => now()->addHour()]);
    $m2m = makeM2mClient($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test',
        ])
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Mint gate (terminal status)
// ---------------------------------------------------------------------------

test('mint gate: participant status=completato → 409', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org);
    $m2m = makeM2mClient($org);

    // Create a participant that has completed
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'done-cand',
        'display_name' => 'Done',
        'status' => 'in_valutazione', // must be set via valid chain
    ]);
    $p->save();
    // Force to completato bypassing guard (direct DB update)
    DB::table('participants')
        ->where('id', $p->id)
        ->update(['status' => 'completato']);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'done-cand',
            'display_name' => 'Done',
        ])
        ->assertStatus(409);
});

test('mint gate: participant status=errore → 409', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org);
    $m2m = makeM2mClient($org);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'errored-cand',
        'display_name' => 'Errored',
        'status' => 'in_attesa',
    ]);
    $p->save();
    DB::table('participants')
        ->where('id', $p->id)
        ->update(['status' => 'errore']);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'errored-cand',
            'display_name' => 'Errored',
        ])
        ->assertStatus(409);
});

test('mint gate: participant status=in_attesa → mints normally (201)', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org);
    $m2m = makeM2mClient($org);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'existing-attesa',
        'display_name' => 'In Attesa',
        'status' => 'in_attesa',
    ]);
    $p->save();

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'existing-attesa',
            'display_name' => 'In Attesa',
        ])
        ->assertStatus(201);
});

// ---------------------------------------------------------------------------
// Cross-org
// ---------------------------------------------------------------------------

test('cross-org project_id → 404', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $m2mA = makeM2mClient($orgA);

    // Create project in org B
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);
    $projectB = Project::factory()->create(['status' => 'active']);

    // Org A client tries to use org B's project_id
    $this->withHeaders(['Authorization' => 'Bearer '.$m2mA['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $projectB->id,
            'candidate_ref' => 'cand-001',
            'display_name' => 'Test',
        ])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// No Redis write at mint
// ---------------------------------------------------------------------------

test('no Redis sso_jti: key written at mint time', function (): void {
    $org = Organization::factory()->create();
    $project = makeActiveProject($org);
    $m2m = makeM2mClient($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/sso-link', [
            'project_id' => $project->id,
            'candidate_ref' => 'cand-redis-test',
            'display_name' => 'Test',
        ]);

    $response->assertStatus(201);

    $token = $response->json('token');
    $payload = JWTAuth::setToken($token)->getPayload();
    $jti = $payload->get('jti');

    // The jti key must NOT be in cache at this point (consume only at exchange)
    expect(Cache::has('sso_jti:'.$jti))->toBeFalse();
});

// ---------------------------------------------------------------------------
// role_code inheritance (sso-link-role-code-default)
//
// The mint accepted an omitted role_code and the exchange refused the result:
// the exchange requires the claim to EQUAL the project's role, and null never
// does. The API returned 201 with a credential it would later refuse — and
// because the exchange consumes the jti before evaluating the gates, that link
// was also spent, so retrying the same URL could never work.
// ---------------------------------------------------------------------------

test('an omitted role_code is inherited from a standard project', function (): void {
    $org = Organization::factory()->create();
    $auth = makeM2mClient($org);
    $project = makeActiveProject($org, ['assessment_type' => 'standard', 'role_code' => 'ICO']);

    $response = $this->withToken($auth['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'inherit-1',
        'display_name' => 'Mario Rossi',
    ]);

    $response->assertCreated();

    $claims = JWTAuth::setToken($response->json('token'))->getPayload();

    expect($claims->get('role_code'))->toBe('ICO');
});

test('a token minted without an explicit role_code is redeemable', function (): void {
    $org = Organization::factory()->create();
    $auth = makeM2mClient($org);
    $project = makeActiveProject($org, ['assessment_type' => 'standard', 'role_code' => 'FLL']);

    $token = $this->withToken($auth['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'inherit-2',
        'display_name' => 'Mario Rossi',
    ])->json('token');

    // The whole point: a 201 must mean the token in that response works.
    $this->getJson("/api/sso/exchange?token={$token}")->assertOk();
});

test('a supplied role_code is still asserted, not silently replaced', function (): void {
    $org = Organization::factory()->create();
    $auth = makeM2mClient($org);
    $project = makeActiveProject($org, ['assessment_type' => 'standard', 'role_code' => 'ICO']);

    // A caller who states a role is asserting something. Overwriting it with
    // the project's value would hide the integration bug this 422 reveals.
    $this->withToken($auth['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'inherit-3',
        'display_name' => 'Mario Rossi',
        'role_code' => 'BUL',
    ])->assertStatus(422);
});

test('a potential project still mints a null role_code', function (): void {
    $org = Organization::factory()->create();
    $auth = makeM2mClient($org);
    $project = makeActiveProject($org, ['assessment_type' => 'potential', 'role_code' => null]);

    $response = $this->withToken($auth['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'inherit-4',
        'display_name' => 'Mario Rossi',
    ]);

    $response->assertCreated();

    $claims = JWTAuth::setToken($response->json('token'))->getPayload();

    // Nothing to inherit: a potential project has no project-level role.
    expect($claims->get('role_code'))->toBeNull();
});
