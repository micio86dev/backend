<?php

declare(strict_types=1);

/**
 * RED→GREEN — 7.2: RBAC gates feature tests (C4).
 *
 * - viewer → POST → 403; PATCH → 403; DELETE → 403
 * - operator → POST → 201; PATCH → 200
 * - admin → DELETE → 204
 * - viewer → GET /api/projects → 200
 *
 * Refs spec: RBAC Gates.
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function rbacUser(Organization $org, string $roleName): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => $roleName, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('viewer → POST /api/projects → 403', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = rbacUser($org, 'viewer');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug' => 'viewer-test',
        'name' => 'Test',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'competency_ids' => [],
    ])->assertForbidden();
});

test('viewer → PATCH /api/projects/{id} → 403', function (): void {
    $org = Organization::factory()->create();
    ['token' => $viewerToken] = rbacUser($org, 'viewer');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    $this->withToken($viewerToken)->patchJson("/api/projects/{$project->id}", ['name' => 'Changed'])
        ->assertForbidden();
});

test('viewer → DELETE /api/projects/{id} → 403', function (): void {
    $org = Organization::factory()->create();
    ['token' => $viewerToken] = rbacUser($org, 'viewer');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    $this->withToken($viewerToken)->deleteJson("/api/projects/{$project->id}")->assertForbidden();
});

test('operator → POST → 201; PATCH → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = rbacUser($org, 'operator');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug' => 'operator-project',
        'name' => 'Operator Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'competency_ids' => [],
    ]);
    $response->assertCreated();

    $projectId = $response->json('data.id');
    $this->withToken($token)->patchJson("/api/projects/{$projectId}", ['name' => 'Updated'])->assertOk();
});

test('admin → DELETE → 204', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = rbacUser($org, 'admin');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    $this->withToken($token)->deleteJson("/api/projects/{$project->id}")->assertNoContent();
});

test('viewer → GET /api/projects → 200', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = rbacUser($org, 'viewer');

    $this->withToken($token)->getJson('/api/projects')->assertOk();
});
