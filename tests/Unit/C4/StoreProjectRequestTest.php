<?php

declare(strict_types=1);

/**
 * RED — 5.1: StoreProjectRequest validation logic (C4).
 *
 * Tests the FormRequest rules, cross-field validators, and gap 422.
 * Uses HTTP integration tests (postJson) so the full middleware + request lifecycle runs.
 *
 * Refs spec: FV Reference-Pin; assessment_type Invariants; potential Catalog Prerequisite Guard;
 *            slug uniqueness; RBAC Gates.
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

// ─── Helper: create an org + admin user + JWT token ──────────────────────────

function makeAdminUser(Organization $org): array
{
    $user = \App\Models\User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

function makeViewerUser(Organization $org): array
{
    $user = \App\Models\User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'viewer', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

function makeOperatorUser(Organization $org): array
{
    $user = \App\Models\User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

function buildStandardPayload(int $fvId, string $roleCode, array $competencyIds): array
{
    return [
        'framework_version_id' => $fvId,
        'slug'                 => 'test-project-' . uniqid(),
        'name'                 => 'Test Project',
        'assessment_type'      => 'standard',
        'role_code'            => $roleCode,
        'language'             => 'en',
        'competency_ids'       => $competencyIds,
    ];
}

// ─── assessment_type validation ───────────────────────────────────────────────

test('store: assessment_type must be in standard|potential', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-1',
            'name'                 => 'Test',
            'assessment_type'      => 'invalid',
            'language'             => 'en',
        ])
        ->assertUnprocessable();
});

// ─── FV cross-org rejection ───────────────────────────────────────────────────

test('store: cross-org framework_version_id → 422 (org-scoped Rule::exists)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $tokenA] = makeAdminUser($orgA);

    $resolver = app(TenantResolver::class);
    // Create FV under org B
    $resolver->setOrgId($orgB->id);
    $fvB = FrameworkVersion::factory()->create(['organization_id' => $orgB->id]);

    $resolver->setOrgId($orgA->id);
    $this->withToken($tokenA)
        ->postJson('/api/projects', [
            'framework_version_id' => $fvB->id, // belongs to org B
            'slug'                 => 'test-cross-org',
            'name'                 => 'Cross Org',
            'assessment_type'      => 'standard',
            'role_code'            => 'ICO',
            'language'             => 'en',
            'competency_ids'       => [],
        ])
        ->assertUnprocessable();
});

// ─── Slug uniqueness ──────────────────────────────────────────────────────────

test('store: slug unique per org — duplicate slug → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // Seed so we can create a standard project with ICO competencies
    (new FrameworkCatalogSeeder())->run();
    $ico = Role::where('code', 'ICO')->first();
    $competencyId = \Illuminate\Support\Facades\DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->value('competency_id');

    $payload = buildStandardPayload($fv->id, 'ICO', [$competencyId]);
    $payload['slug'] = 'duplicate-slug';

    $this->withToken($token)->postJson('/api/projects', $payload)->assertCreated();

    $payload['slug'] = 'duplicate-slug';
    $this->withToken($token)->postJson('/api/projects', $payload)->assertUnprocessable();
});

test('store: slug from soft-deleted project is reusable → 201', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    (new FrameworkCatalogSeeder())->run();
    $ico = Role::where('code', 'ICO')->first();
    $competencyId = \Illuminate\Support\Facades\DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->value('competency_id');

    $payload = buildStandardPayload($fv->id, 'ICO', [$competencyId]);
    $payload['slug'] = 'reusable-slug';

    $response = $this->withToken($token)->postJson('/api/projects', $payload);
    $response->assertCreated();

    $projectId = $response->json('data.id');
    Project::find($projectId)->delete(); // soft-delete

    // Use a new FV since the first is now locked
    $resolver->setOrgId($org->id);
    $fv2 = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $payload2 = buildStandardPayload($fv2->id, 'ICO', [$competencyId]);
    $payload2['slug'] = 'reusable-slug'; // same slug — should be reusable

    $this->withToken($token)->postJson('/api/projects', $payload2)->assertCreated();
});

// ─── language validation ──────────────────────────────────────────────────────

test('store: language must be in supported_locales', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-lang',
            'name'                 => 'Test',
            'assessment_type'      => 'standard',
            'role_code'            => 'ICO',
            'language'             => 'xx', // unsupported
            'competency_ids'       => [],
        ])
        ->assertUnprocessable();
});

// ─── standard assessment_type invariants ──────────────────────────────────────

test('store: standard with invalid role_code → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-role',
            'name'                 => 'Test',
            'assessment_type'      => 'standard',
            'role_code'            => 'INVALID',
            'language'             => 'en',
            'competency_ids'       => [],
        ])
        ->assertUnprocessable();
});

test('store: standard with out-of-role competency → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    (new FrameworkCatalogSeeder())->run();

    // Get a competency assigned to FLL but NOT to ICO
    $ico = Role::where('code', 'ICO')->firstOrFail();
    $fll = Role::where('code', 'FLL')->firstOrFail();

    $icoIds = \Illuminate\Support\Facades\DB::table('framework_role_competency')
        ->where('role_id', $ico->id)->pluck('competency_id')->toArray();
    $fllIds = \Illuminate\Support\Facades\DB::table('framework_role_competency')
        ->where('role_id', $fll->id)->pluck('competency_id')->toArray();

    $fllOnlyIds = array_diff($fllIds, $icoIds);
    expect($fllOnlyIds)->not->toBeEmpty('Need a competency in FLL but not ICO for this test');
    $fllOnlyId = array_values($fllOnlyIds)[0];

    $this->withToken($token)
        ->postJson('/api/projects', buildStandardPayload($fv->id, 'ICO', [$fllOnlyId]))
        ->assertUnprocessable();
});

test('store: standard with potential-type competency (MTG) → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    (new FrameworkCatalogSeeder())->run();

    // Seed MTG/LAT as potential competencies
    $mtg = Competency::firstOrCreate(['code' => 'MTG'], [
        'type' => 'potential',
        'name' => json_encode(['en' => 'Managing Transition & Growth']),
        'definition' => json_encode(['en' => 'Test']),
    ]);

    $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-mtg',
            'name'                 => 'Test',
            'assessment_type'      => 'standard',
            'role_code'            => 'ICO',
            'language'             => 'en',
            'competency_ids'       => [$mtg->id],
        ])
        ->assertUnprocessable();
});

// ─── potential assessment_type invariants ─────────────────────────────────────

test('store: potential with non-null role_code → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-potential-role',
            'name'                 => 'Test',
            'assessment_type'      => 'potential',
            'role_code'            => 'ICO', // must be null for potential
            'language'             => 'en',
            'competency_ids'       => [],
        ])
        ->assertUnprocessable();
});

test('store: mixed standard+potential competencies → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    (new FrameworkCatalogSeeder())->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $standardId = \Illuminate\Support\Facades\DB::table('framework_role_competency')
        ->where('role_id', $ico->id)->value('competency_id');

    $mtg = Competency::firstOrCreate(['code' => 'MTG'], [
        'type' => 'potential',
        'name' => json_encode(['en' => 'MTG']),
        'definition' => json_encode(['en' => 'Test']),
    ]);

    $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-mixed',
            'name'                 => 'Test',
            'assessment_type'      => 'potential',
            'role_code'            => null,
            'language'             => 'en',
            'competency_ids'       => [$standardId, $mtg->id], // mixed types
        ])
        ->assertUnprocessable();
});

// ─── potential catalog prerequisite guard ─────────────────────────────────────

test('store: potential project blocked when MTG/LAT not in catalog → 422 POTENTIAL_CATALOG_INCOMPLETE', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // Ensure MTG and LAT are NOT seeded
    Competency::where('code', 'MTG')->delete();
    Competency::where('code', 'LAT')->delete();

    $response = $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-potential-no-catalog',
            'name'                 => 'Test',
            'assessment_type'      => 'potential',
            'role_code'            => null,
            'language'             => 'en',
            'competency_ids'       => [],
        ]);

    $response->assertUnprocessable();
    expect($response->json('code'))->toBe('POTENTIAL_CATALOG_INCOMPLETE');
});

test('store: potential blocked when only MTG seeded (partial catalog) → 422 POTENTIAL_CATALOG_INCOMPLETE', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    Competency::where('code', 'MTG')->delete();
    Competency::where('code', 'LAT')->delete();

    // Only seed MTG
    Competency::create([
        'code' => 'MTG',
        'type' => 'potential',
        'name' => json_encode(['en' => 'MTG']),
        'definition' => json_encode(['en' => 'Test']),
    ]);

    $response = $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-potential-partial',
            'name'                 => 'Test',
            'assessment_type'      => 'potential',
            'role_code'            => null,
            'language'             => 'en',
            'competency_ids'       => [],
        ]);

    $response->assertUnprocessable();
    expect($response->json('code'))->toBe('POTENTIAL_CATALOG_INCOMPLETE');
});

test('store: POTENTIAL_CATALOG_INCOMPLETE runs BEFORE subset cross-field validation', function (): void {
    // If catalog incomplete, we get POTENTIAL_CATALOG_INCOMPLETE — not a generic subset error.
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    Competency::where('code', 'MTG')->delete();
    Competency::where('code', 'LAT')->delete();

    // Pass an invalid competency_ids that would fail subset check too
    $response = $this->withToken($token)
        ->postJson('/api/projects', [
            'framework_version_id' => $fv->id,
            'slug'                 => 'test-order',
            'name'                 => 'Test',
            'assessment_type'      => 'potential',
            'role_code'            => null,
            'language'             => 'en',
            'competency_ids'       => [99999], // invalid ID — should not matter
        ]);

    $response->assertUnprocessable();
    expect($response->json('code'))->toBe('POTENTIAL_CATALOG_INCOMPLETE');
});

// ─── webhook_url nullable ─────────────────────────────────────────────────────

test('store: webhook_url must be a valid URL when provided', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = makeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    (new FrameworkCatalogSeeder())->run();
    $ico = Role::where('code', 'ICO')->firstOrFail();
    $competencyId = \Illuminate\Support\Facades\DB::table('framework_role_competency')
        ->where('role_id', $ico->id)->value('competency_id');

    $payload = buildStandardPayload($fv->id, 'ICO', [$competencyId]);
    $payload['webhook_url'] = 'not-a-url';

    $this->withToken($token)->postJson('/api/projects', $payload)->assertUnprocessable();
});
