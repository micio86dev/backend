<?php

declare(strict_types=1);

/**
 * RED — 5.3: UpdateProjectRequest validation logic (C4).
 *
 * Tests immutability enforcement, lifecycle guard, slug self-ignore, org-scoped FV validation.
 *
 * Refs spec: Immutable-Field Enforcement; Status Lifecycle; slug uniqueness; UpdateProjectRequest.
 */

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function makeAdminUserForUpdate(Organization $org): array
{
    $user = \App\Models\User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);
    return ['user' => $user, 'token' => $token];
}

// ─── org-scoped FV validation in PATCH ────────────────────────────────────────

test('update: framework_version_id from another org → 422', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($orgA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $fvA = FrameworkVersion::factory()->create(['organization_id' => $orgA->id]);

    $resolver->setOrgId($orgB->id);
    $fvB = FrameworkVersion::factory()->create(['organization_id' => $orgB->id]);

    $resolver->setOrgId($orgA->id);

    (new FrameworkCatalogSeeder())->run();
    $ico = Role::where('code', 'ICO')->firstOrFail();
    $cId = \Illuminate\Support\Facades\DB::table('framework_role_competency')
        ->where('role_id', $ico->id)->value('competency_id');

    $resolver->setOrgId($orgA->id);
    $project = Project::factory()->create([
        'framework_version_id' => $fvA->id,
        'status' => 'draft',
    ]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", [
            'framework_version_id' => $fvB->id, // org B's FV — rejected
        ])
        ->assertUnprocessable();
});

// ─── Slug self-ignore ──────────────────────────────────────────────────────────

test('update: PATCH with same slug → 200 (self-ignore)', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'slug' => 'my-slug',
        'status' => 'draft',
    ]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", [
            'slug' => 'my-slug', // same slug — must not fail
        ])
        ->assertOk();
});

test('update: PATCH with another project slug → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $p1 = Project::factory()->create(['framework_version_id' => $fv->id, 'slug' => 'taken-slug', 'status' => 'draft']);
    $p2 = Project::factory()->create(['framework_version_id' => $fv->id, 'slug' => 'other-slug', 'status' => 'draft']);

    $this->withToken($token)
        ->patchJson("/api/projects/{$p2->id}", [
            'slug' => 'taken-slug', // already used by p1
        ])
        ->assertUnprocessable();
});

// ─── Immutability enforcement (active project) ────────────────────────────────

test('update: assessment_type change on active project → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'status' => 'active',
    ]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", [
            'assessment_type' => 'potential',
        ])
        ->assertUnprocessable();
});

test('update: simultaneous status=active + immutable field change → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'status' => 'draft',
    ]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", [
            'status' => 'active',
            'assessment_type' => 'potential', // changing while activating → rejected
        ])
        ->assertUnprocessable();
});

test('update: assessment_type change on draft project → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    (new FrameworkCatalogSeeder())->run();

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'status' => 'draft',
    ]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", [
            'assessment_type' => 'potential',
            'role_code' => null,
            'competency_ids' => [],
        ])
        ->assertOk();
});

test('update: PATCH with unchanged immutable field value + status=active → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'status' => 'active',
    ]);

    // Sending same value for role_code + status=active is ALLOWED (not a change)
    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", [
            'status' => 'active',
            'role_code' => 'ICO', // same value — not a change
        ])
        ->assertOk();
});

test('update: framework_version_id is always prohibited in PATCH (immutable pin)', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
    ]);

    // PATCH with fv id (even the same value) must be rejected — pin is immutable after creation.
    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", [
            'framework_version_id' => (string) $fv->id,
        ])
        ->assertUnprocessable();
});

// ─── Lifecycle transitions ────────────────────────────────────────────────────

test('update: forbidden status transition active→draft → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'active']);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['status' => 'draft'])
        ->assertUnprocessable();
});

test('update: forbidden status transition archived→active → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'archived']);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['status' => 'active'])
        ->assertUnprocessable();
});

test('update: forbidden status transition archived→draft → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'archived']);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['status' => 'draft'])
        ->assertUnprocessable();
});

test('update: valid transitions draft→active→gone_live→archived → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUserForUpdate($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'status' => 'draft']);

    // draft → active
    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['status' => 'active'])
        ->assertOk();

    // active → gone_live
    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['status' => 'gone_live'])
        ->assertOk();

    // gone_live → archived
    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['status' => 'archived'])
        ->assertOk();
});
