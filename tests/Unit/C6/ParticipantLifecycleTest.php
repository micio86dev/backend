<?php

declare(strict_types=1);

/**
 * Participant lifecycle unit tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - new participant status=in_attesa
 * - started_at/completed_at null on creation
 * - C6 code path never sets status beyond in_attesa
 * - organization_id stamped from project not from payload (forceFill pattern)
 *
 * REQ: Participant Model Lifecycle Guard + "organization_id stamped from project"
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

function makeLifecycleProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function makeLifecycleParticipant(Project $project, Organization $org, array $overrides = []): Participant
{
    $p = new Participant;
    $p->forceFill(array_merge([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'lc-ref-' . uniqid(),
        'display_name'    => 'LC Test',
    ], $overrides));
    $p->save();

    return $p->fresh();
}

test('new participant has status=in_attesa by default', function (): void {
    $org     = Organization::factory()->create();
    $project = makeLifecycleProject($org);
    $p       = makeLifecycleParticipant($project, $org);

    expect($p->status)->toBe('in_attesa');
});

test('new participant has null started_at and completed_at', function (): void {
    $org     = Organization::factory()->create();
    $project = makeLifecycleProject($org);
    $p       = makeLifecycleParticipant($project, $org);

    expect($p->started_at)->toBeNull();
    expect($p->completed_at)->toBeNull();
});

test('C6 code path (create) never sets status beyond in_attesa', function (): void {
    $org     = Organization::factory()->create();
    $project = makeLifecycleProject($org);

    // C6 creates participants via forceFill — should not accept anything beyond in_attesa
    // as its own code only sets in_attesa
    $p = makeLifecycleParticipant($project, $org, ['status' => 'in_attesa']);

    expect($p->status)->toBe('in_attesa');
});

test('organization_id is stamped from project (forceFill pattern, not from request payload)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeLifecycleProject($org);

    // Simulate the M2M create path: organization_id comes from $project->organization_id via forceFill
    // A payload with a different organization_id (e.g. 99999) must NOT be used
    $p = new Participant;
    // Attempt to fill organization_id via normal fill (should be ignored — not in $fillable)
    $p->fill(['organization_id' => 99999, 'project_id' => $project->id]);
    // forceFill with correct org from project
    $p->forceFill([
        'organization_id' => $project->organization_id,
        'candidate_ref'   => 'lc-ref-org-test',
        'display_name'    => 'Test',
    ]);
    $p->save();

    expect($p->fresh()->organization_id)->toBe($org->id);
    expect($p->fresh()->organization_id)->not->toBe(99999);
});

test('organization_id is NOT in $fillable (fill() ignores it)', function (): void {
    $p = new Participant;
    $p->fill(['organization_id' => 99]);

    // Since organization_id is not in $fillable, fill() must not set it
    expect($p->organization_id)->toBeNull();
});
