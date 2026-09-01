<?php

declare(strict_types=1);

/**
 * Participant model unit tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - organization_id NOT in $fillable (named security invariant)
 * - No global scopes (NOT TenantModel)
 * - Participant::all() returns all orgs (no hidden scope)
 * - started_at / completed_at cast to datetime
 * - status default is 'in_attesa'
 * - belongsTo relations exist
 *
 * REQ: Participant Model and Schema — security invariants + "Explicit org filter test — no global scope"
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

test('organization_id is NOT in $fillable (named security invariant)', function (): void {
    $participant = new Participant;

    expect($participant->getFillable())->not->toContain('organization_id');
});

test('Participant implements AuthenticatableContract', function (): void {
    expect(Participant::class)->toImplement(AuthenticatableContract::class);
});

test('Participant does NOT extend TenantModel (no global scopes)', function (): void {
    $scopes = (new Participant)->getGlobalScopes();

    expect($scopes)->toBeEmpty('Participant must have no global scopes (not a TenantModel)');
});

test('Participant::all() returns participants from multiple orgs (no hidden org scope)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $projectA = createProjectForOrg($orgA);
    $projectB = createProjectForOrg($orgB);

    $pA = createParticipant($projectA, $orgA, 'ref-a');
    $pB = createParticipant($projectB, $orgB, 'ref-b');

    $all = Participant::all();

    expect($all->pluck('id'))->toContain($pA->id)
        ->toContain($pB->id);
});

test('started_at is cast to datetime', function (): void {
    $participant = new Participant(['started_at' => '2025-01-01 00:00:00']);

    // Before save, verify the cast type definition is correct
    expect($participant->getCasts())->toHaveKey('started_at');
    expect($participant->getCasts()['started_at'])->toBe('datetime');
});

test('completed_at is cast to datetime', function (): void {
    $participant = new Participant(['completed_at' => '2025-01-01 00:00:00']);

    expect($participant->getCasts())->toHaveKey('completed_at');
    expect($participant->getCasts()['completed_at'])->toBe('datetime');
});

test('status default is in_attesa', function (): void {
    $org = Organization::factory()->create();
    $project = createProjectForOrg($org);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'default-test',
        'display_name' => 'Test Candidate',
        'email' => uniqid('cand-').'@example.test',
    ]);
    $participant->save();

    expect($participant->fresh()->status)->toBe('in_attesa');
});

test('Participant has a belongsTo project relation', function (): void {
    $org = Organization::factory()->create();
    $project = createProjectForOrg($org);
    $participant = createParticipant($project, $org, 'rel-test');

    expect($participant->project)->toBeInstanceOf(Project::class);
    expect($participant->project->id)->toBe($project->id);
});

test('Participant has a belongsTo organization relation', function (): void {
    $org = Organization::factory()->create();
    $project = createProjectForOrg($org);
    $participant = createParticipant($project, $org, 'rel-org-test');

    expect($participant->organization)->toBeInstanceOf(Organization::class);
    expect($participant->organization->id)->toBe($org->id);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createProjectForOrg(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create([
        'status' => 'active',
    ]);
}

function createParticipant(Project $project, Organization $org, string $ref): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => $ref,
        'display_name' => 'Test Candidate',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_attesa',
    ]);
    $p->save();

    return $p;
}
