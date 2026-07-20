<?php

declare(strict_types=1);

/**
 * Cross-tenant isolation tests for InterviewSession (C7a).
 *
 * Asserts: a candidate from org A cannot query InterviewSession rows from org B
 * via any model query (TenantScoped global scope asserted).
 *
 * Uses Pest dataset for multiple isolation vectors.
 *
 * Task: 14.1 (PR 1) — Tenant isolation
 * REQ: Cross-tenant security — session rows from other orgs must be invisible
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function isolationOrg(): Organization
{
    return Organization::factory()->create();
}

function isolationProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    return Project::factory()->create(['status' => 'active']);
}

function isolationParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'iso-' . uniqid(),
        'display_name'    => 'Isolation Test',
        'status'          => 'in_attesa',
    ]);
    $p->save();
    return $p->fresh();
}

function isolationSession(Organization $org, Participant $participant, Project $project, string $code = 'PRS'): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id'       => $participant->id,
        'project_id'           => $project->id,
        'question_index'       => 0,
        'competency_code'      => $code,
        'framework_version_id' => $project->framework_version_id,
        'provider'             => 'heygen',
        'status'               => 'pending',
    ]);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('InterviewSession::all() scoped to org A does NOT return org B sessions', function (): void {
    $orgA         = isolationOrg();
    $orgB         = isolationOrg();
    $projectA     = isolationProject($orgA);
    $projectB     = isolationProject($orgB);
    $participantA = isolationParticipant($orgA, $projectA);
    $participantB = isolationParticipant($orgB, $projectB);

    $sessionA = isolationSession($orgA, $participantA, $projectA, 'PRS');
    $sessionB = isolationSession($orgB, $participantB, $projectB, 'STG');

    // Switch context to org A
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $ids = InterviewSession::all()->pluck('id');

    expect($ids)->toContain($sessionA->id);
    expect($ids)->not->toContain($sessionB->id);
});

test('InterviewSession::where(participant_id) scoped to org A cannot find org B session by ID', function (): void {
    $orgA         = isolationOrg();
    $orgB         = isolationOrg();
    $projectA     = isolationProject($orgA);
    $projectB     = isolationProject($orgB);
    $participantA = isolationParticipant($orgA, $projectA);
    $participantB = isolationParticipant($orgB, $projectB);

    $sessionB = isolationSession($orgB, $participantB, $projectB, 'STG');

    // Switch context to org A
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    // Even if an attacker passes the correct session ID, TenantScoped filters it out.
    $found = InterviewSession::find($sessionB->id);

    expect($found)->toBeNull('org A must not find org B sessions via find()');
});

test('InterviewSession::findOrFail() throws for org B session when scoped to org A', function (): void {
    $orgA         = isolationOrg();
    $orgB         = isolationOrg();
    $projectA     = isolationProject($orgA);
    $projectB     = isolationProject($orgB);
    $participantA = isolationParticipant($orgA, $projectA);
    $participantB = isolationParticipant($orgB, $projectB);

    $sessionB = isolationSession($orgB, $participantB, $projectB, 'STG');

    // Switch context to org A
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    expect(fn () => InterviewSession::findOrFail($sessionB->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('TenantScoped bypass=true allows cross-org session visibility (superadmin mode)', function (): void {
    $orgA         = isolationOrg();
    $orgB         = isolationOrg();
    $projectA     = isolationProject($orgA);
    $projectB     = isolationProject($orgB);
    $participantA = isolationParticipant($orgA, $projectA);
    $participantB = isolationParticipant($orgB, $projectB);

    $sessionA = isolationSession($orgA, $participantA, $projectA, 'PRS');
    $sessionB = isolationSession($orgB, $participantB, $projectB, 'STG');

    // Superadmin bypass — should see ALL sessions
    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true);

    $ids = InterviewSession::all()->pluck('id');

    expect($ids)->toContain($sessionA->id);
    expect($ids)->toContain($sessionB->id);

    // Cleanup bypass
    $resolver->setBypass(false);
});

// ─── Dataset: multiple query patterns all respect tenant scope ─────────────────

test('cross-tenant isolation via dataset', function (string $queryMethod) {
    $orgA         = isolationOrg();
    $orgB         = isolationOrg();
    $projectA     = isolationProject($orgA);
    $projectB     = isolationProject($orgB);
    $participantA = isolationParticipant($orgA, $projectA);
    $participantB = isolationParticipant($orgB, $projectB);

    $sessionB = isolationSession($orgB, $participantB, $projectB, 'COM');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $found = match ($queryMethod) {
        'find'  => InterviewSession::find($sessionB->id),
        'first' => InterviewSession::where('id', $sessionB->id)->first(),
        'count' => InterviewSession::where('id', $sessionB->id)->count() > 0 ? true : null,
    };

    expect($found)->toBeNull("Query '{$queryMethod}' must not expose org B session to org A");
})->with(['find', 'first', 'count']);
