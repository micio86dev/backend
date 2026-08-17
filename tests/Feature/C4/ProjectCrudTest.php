<?php

declare(strict_types=1);

/**
 * RED→GREEN — 7.1: Project CRUD feature tests (C4).
 *
 * - POST /api/projects → 201; org_id stamped from auth
 * - GET /api/projects → lists only own-org
 * - GET /api/projects/{id} → 200 own; 404 cross-org
 * - PATCH /api/projects/{id} → 200; 404 cross-org
 * - DELETE /api/projects/{id} → 204 soft-delete; not in index
 * - DELETE cross-org → 404
 * - webhook_secret absent from response
 *
 * Refs spec: Org-Scoped Project Entity; CRUD API; cross-tenant isolation.
 */

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function crudAdminUser(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

function crudStandardPayload(int $fvId, array $competencyIds = []): array
{
    return [
        'framework_version_id' => $fvId,
        'slug' => 'test-proj-'.uniqid(),
        'name' => 'Test Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'competency_ids' => $competencyIds,
    ];
}

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('POST /api/projects → 201; organization_id stamped from auth, not body', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($orgA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $orgA->id]);

    $payload = crudStandardPayload($fv->id);
    $payload['organization_id'] = $orgB->id; // attempt to forge org B

    $response = $this->withToken($token)->postJson('/api/projects', $payload);
    $response->assertCreated();

    // org_id must be org A (stamped from auth, not request body)
    $orgIdInResponse = $response->json('data.organization_id');
    expect($orgIdInResponse)->toBe($orgA->id);

    // Verify in DB
    $project = Project::find($response->json('data.id'));
    expect($project->organization_id)->toBe($orgA->id);
});

test('GET /api/projects → lists only own-org (3 org-A, 2 org-B → returns 3)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($orgA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $fvA = FrameworkVersion::factory()->create(['organization_id' => $orgA->id]);

    // Create 3 org-A projects
    for ($i = 0; $i < 3; $i++) {
        Project::factory()->create(['framework_version_id' => $fvA->id]);
    }

    // Create 2 org-B projects
    $resolver->setOrgId($orgB->id);
    $fvB = FrameworkVersion::factory()->create(['organization_id' => $orgB->id]);
    for ($i = 0; $i < 2; $i++) {
        Project::factory()->create(['framework_version_id' => $fvB->id]);
    }

    $resolver->setOrgId($orgA->id);
    $response = $this->withToken($token)->getJson('/api/projects');
    $response->assertOk()->assertJsonCount(3, 'data');
});

test('GET /api/projects/{id} → 200 own; 404 cross-org', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $tokenA] = crudAdminUser($orgA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $fvA = FrameworkVersion::factory()->create(['organization_id' => $orgA->id]);
    $projectA = Project::factory()->create(['framework_version_id' => $fvA->id]);

    // Own project → 200
    $this->withToken($tokenA)->getJson("/api/projects/{$projectA->id}")->assertOk();

    // Create org-B project
    $resolver->setOrgId($orgB->id);
    $fvB = FrameworkVersion::factory()->create(['organization_id' => $orgB->id]);
    $projectB = Project::factory()->create(['framework_version_id' => $fvB->id]);

    // Cross-org → 404
    $this->withToken($tokenA)->getJson("/api/projects/{$projectB->id}")->assertNotFound();
});

test('PATCH /api/projects/{id} → 200 own; 404 cross-org', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $tokenA] = crudAdminUser($orgA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $fvA = FrameworkVersion::factory()->create(['organization_id' => $orgA->id]);
    $projectA = Project::factory()->create(['framework_version_id' => $fvA->id]);

    // Own project → 200
    $this->withToken($tokenA)->patchJson("/api/projects/{$projectA->id}", ['name' => 'Updated'])->assertOk();

    // Create org-B project
    $resolver->setOrgId($orgB->id);
    $fvB = FrameworkVersion::factory()->create(['organization_id' => $orgB->id]);
    $projectB = Project::factory()->create(['framework_version_id' => $fvB->id]);

    // Cross-org → 404
    $this->withToken($tokenA)->patchJson("/api/projects/{$projectB->id}", ['name' => 'Hacked'])->assertNotFound();
});

test('DELETE /api/projects/{id} → 204 soft-delete; deleted_at set; not in index', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    $this->withToken($token)->deleteJson("/api/projects/{$project->id}")->assertNoContent();

    // deleted_at must be set
    expect(Project::withTrashed()->find($project->id)->deleted_at)->not->toBeNull();

    // Must not appear in index
    $indexResponse = $this->withToken($token)->getJson('/api/projects');
    $ids = collect($indexResponse->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($project->id);
});

test('DELETE cross-org → 404', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $tokenA] = crudAdminUser($orgA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $fvB = FrameworkVersion::factory()->create(['organization_id' => $orgB->id]);
    $projectB = Project::factory()->create(['framework_version_id' => $fvB->id]);

    $this->withToken($tokenA)->deleteJson("/api/projects/{$projectB->id}")->assertNotFound();
});

// bars-coverage-visibility Phase 1.1 — documents the safety net that makes
// hydration + submission in ProjectForm.vue safe to add: a PATCH that never
// mentions competency_ids must not touch the pivot at all. This is what
// protects every existing project from a `sync([])` wipe once the client
// starts submitting competency_ids on every save (see ProjectController::
// update — the sync() call is guarded by `$competencyIds !== null`).
test('PATCH /api/projects/{id} without competency_ids leaves project_competencies unchanged', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    $competency = Competency::factory()->create(['type' => 'standard']);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['name' => 'Renamed, no competency_ids in payload'])
        ->assertOk();

    expect($project->competencies()->pluck('framework_competencies.id')->all())->toBe([$competency->id]);
});

test('webhook_secret is absent from response', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->postJson('/api/projects', array_merge(
        crudStandardPayload($fv->id),
        ['webhook_secret' => 'super-secret-value']
    ));
    $response->assertCreated();

    // webhook_secret must not appear in the response
    $data = $response->json('data');
    expect(array_key_exists('webhook_secret', $data))->toBeFalse();
    expect(json_encode($data))->not->toContain('super-secret-value');
});
