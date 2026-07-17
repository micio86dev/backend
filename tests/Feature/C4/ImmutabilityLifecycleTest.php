<?php

declare(strict_types=1);

/**
 * RED→GREEN — 7.5: Project immutability + lifecycle gate feature tests (C4).
 *
 * Immutability:
 * - PATCH on gone_live project: cannot change assessment_type → 422
 * - PATCH on gone_live project: cannot change role_code → 422
 * - PATCH on gone_live project: non-immutable field (name) still works → 200
 *
 * Lifecycle transitions:
 * - draft → active → 200
 * - active → gone_live → 200
 * - active → draft (forbidden regression) → 422
 * - gone_live → active (forbidden regression) → 422
 * - gone_live → archived → 200
 * - unknown status → 422
 * - PATCH framework_version_id on any project → 422
 * - Slug reuse after soft-delete: same org, same slug → 201
 *
 * Refs spec: Immutable Fields; Lifecycle Transitions.
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function lifecycleAdminUser(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);
    return ['user' => $user, 'token' => $token];
}

beforeEach(function (): void {
    (new FrameworkCatalogSeeder())->run();
});

// ---- Immutability tests ----

test('PATCH gone_live project: cannot change assessment_type → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status'               => 'gone_live',
        'assessment_type'      => 'standard',
    ]);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", [
        'assessment_type' => 'potential',
    ])->assertUnprocessable();
});

test('PATCH gone_live project: cannot change role_code → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status'               => 'gone_live',
        'role_code'            => 'ICO',
    ]);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", [
        'role_code' => 'FLL',
    ])->assertUnprocessable();
});

test('PATCH gone_live project: non-immutable field (name) still works → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status'               => 'gone_live',
    ]);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", [
        'name' => 'Updated Name',
    ])->assertOk();
});

// ---- Lifecycle transition tests ----

test('draft → active → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'draft']);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['status' => 'active'])->assertOk();
    expect($project->fresh()->status)->toBe('active');
});

test('active → gone_live → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'active']);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['status' => 'gone_live'])->assertOk();
    expect($project->fresh()->status)->toBe('gone_live');
});

test('active → draft (forbidden regression) → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'active']);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['status' => 'draft'])->assertUnprocessable();
});

test('gone_live → active (forbidden regression) → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'gone_live']);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['status' => 'active'])->assertUnprocessable();
});

test('gone_live → archived → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'gone_live']);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['status' => 'archived'])->assertOk();
    expect($project->fresh()->status)->toBe('archived');
});

test('PATCH unknown status → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'draft']);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['status' => 'INVALID'])->assertUnprocessable();
});

test('PATCH framework_version_id is always rejected → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv1 = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $fv2 = FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv1->id, 'status' => 'draft']);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", [
        'framework_version_id' => $fv2->id,
    ])->assertUnprocessable();
});

test('slug reuse after soft-delete same org same slug → 201', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = lifecycleAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // Create project with slug "reusable-slug"
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'slug'                 => 'reusable-slug',
    ]);

    // Soft-delete it
    $this->withToken($token)->deleteJson("/api/projects/{$project->id}")->assertNoContent();
    expect(Project::withTrashed()->find($project->id)->deleted_at)->not->toBeNull();

    // Create a new project reusing the same slug → must succeed (partial unique index)
    $fv2 = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv2->id,
        'slug'                 => 'reusable-slug',
        'name'                 => 'Recycled Slug Project',
        'assessment_type'      => 'standard',
        'role_code'            => 'ICO',
        'language'             => 'en',
        'competency_ids'       => [],
    ])->assertCreated();
});
