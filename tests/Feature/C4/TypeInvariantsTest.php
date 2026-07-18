<?php

declare(strict_types=1);

/**
 * RED→GREEN — 7.4: assessment_type invariants feature tests (C4).
 *
 * - Valid standard + correct role + subset → 201
 * - standard + invalid role_code → 422
 * - standard + out-of-role competency → 422
 * - standard + potential competency → 422
 * - Valid potential (MTG + LAT seeded) → 201
 * - potential + non-null role_code → 422
 * - potential + standard competency → 422
 * - Mixed types → 422
 * - potential + neither MTG/LAT seeded → 422 POTENTIAL_CATALOG_INCOMPLETE
 * - potential + only MTG seeded → 422 POTENTIAL_CATALOG_INCOMPLETE
 *
 * Refs spec: assessment_type Invariants; potential Catalog Prerequisite Guard.
 */

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function typeAdminUser(Organization $org): array
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

test('valid standard project with correct role and subset → 201', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $competencyId = DB::table('framework_role_competency')
        ->where('role_id', $ico->id)->value('competency_id');

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'valid-standard',
        'name'                 => 'Valid Standard',
        'assessment_type'      => 'standard',
        'role_code'            => 'ICO',
        'language'             => 'en',
        'competency_ids'       => [$competencyId],
    ])->assertCreated();
});

test('standard + invalid role_code → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'bad-role',
        'name'                 => 'Test',
        'assessment_type'      => 'standard',
        'role_code'            => 'INVALID',
        'language'             => 'en',
        'competency_ids'       => [],
    ])->assertUnprocessable();
});

test('standard + out-of-role competency → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $fll = Role::where('code', 'FLL')->firstOrFail();

    $icoIds = DB::table('framework_role_competency')->where('role_id', $ico->id)->pluck('competency_id')->toArray();
    $fllIds = DB::table('framework_role_competency')->where('role_id', $fll->id)->pluck('competency_id')->toArray();
    $fllOnlyId = array_values(array_diff($fllIds, $icoIds))[0];

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'out-of-role',
        'name'                 => 'Test',
        'assessment_type'      => 'standard',
        'role_code'            => 'ICO',
        'language'             => 'en',
        'competency_ids'       => [$fllOnlyId],
    ])->assertUnprocessable();
});

test('standard + potential competency (MTG) → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $mtg = Competency::firstOrCreate(['code' => 'MTG'], [
        'type' => 'potential',
        'name' => json_encode(['en' => 'MTG']),
        'definition' => json_encode(['en' => 'Test']),
    ]);

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'std-plus-potential',
        'name'                 => 'Test',
        'assessment_type'      => 'standard',
        'role_code'            => 'ICO',
        'language'             => 'en',
        'competency_ids'       => [$mtg->id],
    ])->assertUnprocessable();
});

test('valid potential project with MTG + LAT seeded → 201', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $mtg = Competency::firstOrCreate(['code' => 'MTG'], [
        'type' => 'potential',
        'name' => json_encode(['en' => 'MTG']),
        'definition' => json_encode(['en' => 'Test']),
    ]);
    $lat = Competency::firstOrCreate(['code' => 'LAT'], [
        'type' => 'potential',
        'name' => json_encode(['en' => 'LAT']),
        'definition' => json_encode(['en' => 'Test']),
    ]);

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'valid-potential',
        'name'                 => 'Potential Test',
        'assessment_type'      => 'potential',
        'role_code'            => null,
        'language'             => 'en',
        'competency_ids'       => [$mtg->id, $lat->id],
    ])->assertCreated();
});

test('potential + non-null role_code → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'pot-with-role',
        'name'                 => 'Test',
        'assessment_type'      => 'potential',
        'role_code'            => 'ICO',
        'language'             => 'en',
        'competency_ids'       => [],
    ])->assertUnprocessable();
});

test('potential + standard competency (PRS) → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // Seed MTG/LAT so catalog check passes
    Competency::firstOrCreate(['code' => 'MTG'], [
        'type' => 'potential', 'name' => json_encode(['en' => 'MTG']), 'definition' => json_encode(['en' => 'T']),
    ]);
    Competency::firstOrCreate(['code' => 'LAT'], [
        'type' => 'potential', 'name' => json_encode(['en' => 'LAT']), 'definition' => json_encode(['en' => 'T']),
    ]);

    $prs = Competency::where('code', 'PRS')->firstOrFail();

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'pot-std-comp',
        'name'                 => 'Test',
        'assessment_type'      => 'potential',
        'role_code'            => null,
        'language'             => 'en',
        'competency_ids'       => [$prs->id],
    ])->assertUnprocessable();
});

test('mixed standard+potential competencies → 422', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $stdId = DB::table('framework_role_competency')->where('role_id', $ico->id)->value('competency_id');

    $mtg = Competency::firstOrCreate(['code' => 'MTG'], [
        'type' => 'potential', 'name' => json_encode(['en' => 'MTG']), 'definition' => json_encode(['en' => 'T']),
    ]);
    Competency::firstOrCreate(['code' => 'LAT'], [
        'type' => 'potential', 'name' => json_encode(['en' => 'LAT']), 'definition' => json_encode(['en' => 'T']),
    ]);

    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'mixed-types',
        'name'                 => 'Test',
        'assessment_type'      => 'potential',
        'role_code'            => null,
        'language'             => 'en',
        'competency_ids'       => [$stdId, $mtg->id],
    ])->assertUnprocessable();
});

test('potential + neither MTG/LAT seeded → 422 POTENTIAL_CATALOG_INCOMPLETE', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    Competency::where('code', 'MTG')->delete();
    Competency::where('code', 'LAT')->delete();

    $response = $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'no-catalog',
        'name'                 => 'Test',
        'assessment_type'      => 'potential',
        'role_code'            => null,
        'language'             => 'en',
        'competency_ids'       => [],
    ]);
    $response->assertUnprocessable();
    expect($response->json('code'))->toBe('POTENTIAL_CATALOG_INCOMPLETE');
});

test('potential + only MTG seeded → 422 POTENTIAL_CATALOG_INCOMPLETE', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = typeAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    Competency::where('code', 'MTG')->delete();
    Competency::where('code', 'LAT')->delete();

    Competency::create([
        'code' => 'MTG',
        'type' => 'potential',
        'name' => json_encode(['en' => 'MTG']),
        'definition' => json_encode(['en' => 'Test']),
    ]);

    $response = $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug'                 => 'partial-catalog',
        'name'                 => 'Test',
        'assessment_type'      => 'potential',
        'role_code'            => null,
        'language'             => 'en',
        'competency_ids'       => [],
    ]);
    $response->assertUnprocessable();
    expect($response->json('code'))->toBe('POTENTIAL_CATALOG_INCOMPLETE');
});
