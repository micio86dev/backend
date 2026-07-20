<?php

declare(strict_types=1);

/**
 * M2M ParticipantController feature tests (C6 — Participant + SSO Ingress).
 *
 * Covers:
 * - store creates participant with org from project (not from input)
 * - store cross-org project_id → 404
 * - index scoped to caller org (Org B participants absent)
 * - show cross-tenant → 404
 * - organization_id from body ignored (set to 99, persisted as project's org)
 *
 * REQ: M2M Participant CRUD — all scenarios
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeSsoM2mClient(Organization $org, array $abilities = ['participants:create', 'participants:read']): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
        'is_active'       => true,
        'abilities'       => $abilities,
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

function makeSsoProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(array_merge([
        'status' => 'active',
    ], $attrs));
}

function makeSsoParticipant(Project $project, Organization $org, ?string $ref = null): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => $ref ?? 'ref-' . uniqid(),
        'display_name'    => 'Test Candidate',
        'status'          => 'in_attesa',
    ]);
    $p->save();

    return $p;
}

// ---------------------------------------------------------------------------
// store
// ---------------------------------------------------------------------------

test('store creates participant with organization_id from project (not from request)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeSsoProject($org);
    $m2m     = makeSsoM2mClient($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer ' . $m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id'      => $project->id,
            'candidate_ref'   => 'cand-001',
            'display_name'    => 'Test Candidate',
            'organization_id' => 99999, // should be ignored
        ]);

    $response->assertStatus(201);

    $participant = Participant::where('candidate_ref', 'cand-001')->first();
    expect($participant)->not->toBeNull();
    // org from project, NOT from input
    expect($participant->organization_id)->toBe($org->id);
    expect($participant->organization_id)->not->toBe(99999);
});

test('store cross-org project_id → 404', function (): void {
    $orgA    = Organization::factory()->create();
    $orgB    = Organization::factory()->create();
    $m2mA    = makeSsoM2mClient($orgA);
    $projectB = makeSsoProject($orgB);

    $this->withHeaders(['Authorization' => 'Bearer ' . $m2mA['key']])
        ->postJson('/api/m2m/participants', [
            'project_id'    => $projectB->id,
            'candidate_ref' => 'cand-001',
            'display_name'  => 'Test',
        ])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// index
// ---------------------------------------------------------------------------

test('index returns only participants from caller org (Org B participants absent)', function (): void {
    $orgA    = Organization::factory()->create();
    $orgB    = Organization::factory()->create();
    $m2mA    = makeSsoM2mClient($orgA);
    $projectA = makeSsoProject($orgA);
    $projectB = makeSsoProject($orgB);

    $pA = makeSsoParticipant($projectA, $orgA, 'ref-a');
    $pB = makeSsoParticipant($projectB, $orgB, 'ref-b');

    $response = $this->withHeaders(['Authorization' => 'Bearer ' . $m2mA['key']])
        ->getJson('/api/m2m/participants');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($pA->id);
    expect($ids)->not->toContain($pB->id);
});

// ---------------------------------------------------------------------------
// show
// ---------------------------------------------------------------------------

test('show cross-tenant → 404', function (): void {
    $orgA    = Organization::factory()->create();
    $orgB    = Organization::factory()->create();
    $m2mA    = makeSsoM2mClient($orgA);
    $projectB = makeSsoProject($orgB);
    $pB      = makeSsoParticipant($projectB, $orgB);

    $this->withHeaders(['Authorization' => 'Bearer ' . $m2mA['key']])
        ->getJson('/api/m2m/participants/' . $pB->id)
        ->assertNotFound();
});

test('show same org → 200', function (): void {
    $org     = Organization::factory()->create();
    $m2m     = makeSsoM2mClient($org);
    $project = makeSsoProject($org);
    $p       = makeSsoParticipant($project, $org);

    $this->withHeaders(['Authorization' => 'Bearer ' . $m2m['key']])
        ->getJson('/api/m2m/participants/' . $p->id)
        ->assertOk()
        ->assertJsonPath('data.id', $p->id);
});

test('missing participants:create ability → 403 on store', function (): void {
    $org     = Organization::factory()->create();
    $m2m     = makeSsoM2mClient($org, ['participants:read']); // no create
    $project = makeSsoProject($org);

    $this->withHeaders(['Authorization' => 'Bearer ' . $m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id'    => $project->id,
            'candidate_ref' => 'cand-001',
            'display_name'  => 'Test',
        ])
        ->assertForbidden();
});

test('missing participants:read ability → 403 on index', function (): void {
    $org = Organization::factory()->create();
    $m2m = makeSsoM2mClient($org, ['participants:create']); // no read

    $this->withHeaders(['Authorization' => 'Bearer ' . $m2m['key']])
        ->getJson('/api/m2m/participants')
        ->assertForbidden();
});
