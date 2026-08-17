<?php

declare(strict_types=1);

/**
 * RED — POST /api/entry-links RBAC (operator-interview-link, design D2).
 *
 * Minting an entry link starts an assessment, not a read: `admin` and
 * `operator` may mint, `viewer` MUST be denied. Authorization runs FIRST via
 * `ParticipantPolicy::create`, before project resolution — 403 before 404.
 *
 * REQ: Operator-Facing Entry Link Mint Endpoint
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function entryLinkPolicyUser(Organization $org, string $role): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function entryLinkPolicyProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(array_merge([
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

function entryLinkMintPayload(Project $project, array $overrides = []): array
{
    return array_merge([
        'project_id' => $project->id,
        'candidate_ref' => 'policy-cand',
        'display_name' => 'Policy Candidate',
    ], $overrides);
}

test('viewer is denied — 403, no token minted', function (): void {
    $org = Organization::factory()->create();
    $project = entryLinkPolicyProject($org);
    $token = entryLinkPolicyUser($org, 'viewer');

    $this->withToken($token)->postJson('/api/entry-links', entryLinkMintPayload($project))
        ->assertStatus(403);
});

test('operator mints successfully — 201', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = entryLinkPolicyProject($org);
    $token = entryLinkPolicyUser($org, 'operator');

    $this->withToken($token)->postJson('/api/entry-links', entryLinkMintPayload($project))
        ->assertStatus(201);
});

test('admin mints successfully — 201', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = entryLinkPolicyProject($org);
    $token = entryLinkPolicyUser($org, 'admin');

    $this->withToken($token)->postJson('/api/entry-links', entryLinkMintPayload($project))
        ->assertStatus(201);
});

test('cross-org project_id returns 404, matching M2M behaviour', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $token = entryLinkPolicyUser($orgA, 'operator');
    $projectB = entryLinkPolicyProject($orgB);

    $this->withToken($token)->postJson('/api/entry-links', entryLinkMintPayload($projectB))
        ->assertStatus(404);
});

test('no token is rejected with 401', function (): void {
    $org = Organization::factory()->create();
    $project = entryLinkPolicyProject($org);

    $this->postJson('/api/entry-links', entryLinkMintPayload($project))
        ->assertStatus(401);
});
