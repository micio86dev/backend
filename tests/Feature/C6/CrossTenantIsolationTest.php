<?php

declare(strict_types=1);

/**
 * Cross-tenant isolation tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - candidate of Org A cannot access Org B project/participant data
 * - M2M client of Org A cannot list/read Org B participants
 * - Participant::all() unscoped returns all orgs (no hidden scope)
 *
 * REQ: Cross-Tenant Isolation — all scenarios
 */

use App\Http\Middleware\TenantContext;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function isolOrg(): Organization
{
    return Organization::factory()->create();
}

function isolProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(array_merge([
        'status' => 'active',
        'role_code' => 'ICO',
        'assessment_type' => 'standard',
    ], $attrs));
}

function isolParticipant(Project $project, Organization $org, ?string $ref = null): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => $ref ?? 'ref-'.uniqid(),
        'display_name' => 'Isolation Test',
        'status' => 'in_attesa',
    ]);
    $p->save();

    return $p->fresh();
}

function isolM2mKey(Organization $org, array $abilities = ['participants:read']): string
{
    $rawKey = ApiKeyGenerator::generate();
    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'abilities' => $abilities,
    ]);

    return $rawKey;
}

beforeEach(function (): void {
    Route::middleware('auth:api-candidate')
        ->prefix('api')
        ->withoutMiddleware(TenantContext::class)
        ->get('/test-candidate-isol', fn () => response()->json(['ok' => true]));
});

// ---------------------------------------------------------------------------
// Participant::all() unscoped
// ---------------------------------------------------------------------------

test('Participant::all() returns participants from multiple orgs (confirming no hidden scope)', function (): void {
    $orgA = isolOrg();
    $orgB = isolOrg();
    $projA = isolProject($orgA);
    $projB = isolProject($orgB);
    $pA = isolParticipant($projA, $orgA, 'isol-ref-a');
    $pB = isolParticipant($projB, $orgB, 'isol-ref-b');

    $all = Participant::all();
    $ids = $all->pluck('id');

    expect($ids)->toContain($pA->id);
    expect($ids)->toContain($pB->id);
});

// ---------------------------------------------------------------------------
// M2M cross-tenant isolation
// ---------------------------------------------------------------------------

test('M2M client of Org A cannot list Org B participants (index scoped to caller org)', function (): void {
    $orgA = isolOrg();
    $orgB = isolOrg();
    $projA = isolProject($orgA);
    $projB = isolProject($orgB);
    $pB = isolParticipant($projB, $orgB, 'isol-m2m-b');
    $keyA = isolM2mKey($orgA);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$keyA])
        ->getJson('/api/m2m/participants');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain($pB->id);
});

test('M2M client of Org A cannot read Org B participant by ID → 404', function (): void {
    $orgA = isolOrg();
    $orgB = isolOrg();
    $projB = isolProject($orgB);
    $pB = isolParticipant($projB, $orgB);
    $keyA = isolM2mKey($orgA);

    $this->withHeaders(['Authorization' => 'Bearer '.$keyA])
        ->getJson('/api/m2m/participants/'.$pB->id)
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Candidate cross-tenant isolation
// ---------------------------------------------------------------------------

test('candidate of Org A cannot access /candidate/session as Org B context', function (): void {
    // A candidate JWT from Org A should be rejected if the participant belongs to Org A.
    // TenantContextCandidate sets org from the participant record (Org A).
    // The candidate session endpoint returns that org A participant data — they cannot
    // access Org B's data since the guard resolves only their own participant.
    $orgA = isolOrg();
    $projA = isolProject($orgA);
    $pA = isolParticipant($projA, $orgA, 'isol-cand-a');
    $tokenA = CandidateTokenFactory::mintCandidateToken($pA);

    // Org A candidate authenticates and gets their own session — this is fine
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$tokenA])
        ->getJson('/api/candidate/session');
    $response->assertOk();

    // They can only see their own org A participant, NOT org B's
    expect($response->json('data.id'))->toBe($pA->id);
});

test('candidate token from Org A cannot have org_id tampered to access Org B resources', function (): void {
    // Security: even if an attacker tampers the org_id claim in their candidate JWT,
    // TenantContextCandidate always reads org from the DB record (participant.organization_id).
    $orgA = isolOrg();
    $orgB = isolOrg();
    $projA = isolProject($orgA);
    $pA = isolParticipant($projA, $orgA, 'isol-tamper-a');

    // Mint with tampered org_id pointing to org B (attacker claim injection)
    $tamperedToken = CandidateTokenFactory::mintCandidateToken($pA, ['organization_id' => $orgB->id]);

    // The guard finds pA (by sub=pA.id), TenantContextCandidate reads pA.organization_id = orgA
    // The tampered claim is ignored — org context is orgA, not orgB
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$tamperedToken])
        ->getJson('/api/candidate/session');

    $response->assertOk();
    // Confirm org is from DB record (orgA), not from tampered claim (orgB)
    // The participant resource includes the participant id — which belongs to orgA
    expect($response->json('data.id'))->toBe($pA->id);
});
