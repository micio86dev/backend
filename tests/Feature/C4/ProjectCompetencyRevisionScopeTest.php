<?php

declare(strict_types=1);

/**
 * Regression: the competency-picker read endpoints
 * (`GET /framework/roles/{role}/competencies`,
 * `GET /framework/potential-competencies`) must scope to the SAME catalogue
 * revision `StoreProjectRequest`/`UpdateProjectRequest` validate
 * `competency_ids` against — the target `framework_version_id`'s OWN pinned
 * revision, never unconditionally "latest published".
 *
 * Reproduces the reported production defect: an org whose `FrameworkVersion`
 * is pinned to an OLDER revision (the catalogue was republished since that
 * pin was created — a `FrameworkVersion` is pinned once and never
 * retargeted) got competency ids from the picker that the server then
 * refused as `competency_unknown`, even though they were correctly ticked.
 */

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function scopeAdminUser(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

/**
 * Publishes a SECOND, NEWER revision with its own ICO role and its own row
 * for the SAME competency code as one of the baseline's ICO competencies —
 * a full clone under a NEW id (`framework_competencies` rows are per-
 * revision — `CatalogueRevisionResolver`'s own class docblock: "a draft is a
 * full row-set clone").
 *
 * @return array{competencyId: int, competencyCode: string}
 */
function publishNewerRevisionWithClonedIco(): array
{
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $baselineIco = Role::where('revision_id', $baseline->id)->where('code', 'ICO')->firstOrFail();
    $competencyCode = DB::table('framework_role_competency')
        ->join('framework_competencies', 'framework_competencies.id', '=', 'framework_role_competency.competency_id')
        ->where('framework_role_competency.role_id', $baselineIco->id)
        ->value('framework_competencies.code');

    // Content tables are DB-trigger-immutable for a PUBLISHED revision
    // (`framework_catalog_published_content_immutable`) — write the content
    // while this revision is still a draft, then flip it published via a raw
    // update, mirroring `RoleCompetencyPivotTest`'s own pattern for
    // constructing a specific published revision without the full
    // superadmin draft->publish HTTP flow.
    $newer = FrameworkCatalogRevision::factory()->draft()->create();

    $role = Role::factory()->create(['revision_id' => $newer->id, 'code' => 'ICO']);
    $competency = Competency::factory()->create([
        'revision_id' => $newer->id,
        'code' => $competencyCode,
        'type' => 'standard',
    ]);
    $role->competencies()->attach($competency->id, ['position' => 0]);

    DB::table('framework_catalog_revisions')->where('id', $newer->id)->update([
        'state' => 'published',
        'published_at' => now()->addMinute(),
    ]);

    return ['competencyId' => $competency->id, 'competencyCode' => $competencyCode];
}

test('the competency picker scopes to framework_version_id\'s own pinned revision, not latest published', function (): void {
    $fixture = publishNewerRevisionWithClonedIco();

    $org = Organization::factory()->create();
    ['token' => $token] = scopeAdminUser($org);
    app(TenantResolver::class)->setOrgId($org->id);

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    // "an org may legitimately submit an OLDER FrameworkVersion it already
    // holds" — StoreProjectRequest::compositionRevisionId()'s own docblock.
    $fv = FrameworkVersion::factory()->create([
        'organization_id' => $org->id,
        'revision_id' => $baseline->id,
    ]);

    $baselineIco = Role::where('revision_id', $baseline->id)->where('code', 'ICO')->firstOrFail();
    $baselineCompetencyIds = DB::table('framework_role_competency')
        ->where('role_id', $baselineIco->id)
        ->pluck('competency_id')
        ->all();

    $response = $this->withToken($token)
        ->getJson("/api/framework/roles/ICO/competencies?framework_version_id={$fv->id}")
        ->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toEqualCanonicalizing($baselineCompetencyIds)
        ->and($returnedIds)->not->toContain($fixture['competencyId']);

    // The literal reproduction: submit exactly what the (now correctly
    // scoped) picker returned.
    $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug' => 'scope-'.uniqid(),
        'name' => 'Scope Test',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'avatar_template_id' => templateIdForCurrentOrg(),
        'competency_ids' => array_slice($baselineCompetencyIds, 0, 2),
    ])->assertCreated();
});

test('an id from a NEWER revision is still refused against an older-pinned framework_version_id', function (): void {
    $fixture = publishNewerRevisionWithClonedIco();

    $org = Organization::factory()->create();
    ['token' => $token] = scopeAdminUser($org);
    app(TenantResolver::class)->setOrgId($org->id);

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $fv = FrameworkVersion::factory()->create([
        'organization_id' => $org->id,
        'revision_id' => $baseline->id,
    ]);

    $response = $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $fv->id,
        'slug' => 'scope-precision-'.uniqid(),
        'name' => 'Scope Precision Test',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'avatar_template_id' => templateIdForCurrentOrg(),
        'competency_ids' => [$fixture['competencyId']],
    ]);

    $response->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toContain('competency_ids.0');
    expect($response->json('errors')['competency_ids.0'][0])->toBe('competency_unknown');
});

test('without framework_version_id, the picker still falls back to the latest published revision', function (): void {
    $fixture = publishNewerRevisionWithClonedIco();

    $org = Organization::factory()->create();
    ['token' => $token] = scopeAdminUser($org);
    app(TenantResolver::class)->setOrgId($org->id);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies')
        ->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toContain($fixture['competencyId']);
});

test('potential-competencies also scopes to the target framework_version_id, not latest published', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $baselinePotential = Competency::where('revision_id', $baseline->id)->where('type', 'potential')->firstOrFail();

    $newer = FrameworkCatalogRevision::factory()->draft()->create();
    $newerPotential = Competency::factory()->potential()->create([
        'revision_id' => $newer->id,
        'code' => $baselinePotential->code,
    ]);

    DB::table('framework_catalog_revisions')->where('id', $newer->id)->update([
        'state' => 'published',
        'published_at' => now()->addMinute(),
    ]);

    $org = Organization::factory()->create();
    ['token' => $token] = scopeAdminUser($org);
    app(TenantResolver::class)->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create([
        'organization_id' => $org->id,
        'revision_id' => $baseline->id,
    ]);

    $response = $this->withToken($token)
        ->getJson("/api/framework/potential-competencies?framework_version_id={$fv->id}")
        ->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toContain($baselinePotential->id)
        ->and($returnedIds)->not->toContain($newerPotential->id);
});

test('PATCH self-heals default questions for a competency attached before this write path seeded them', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = scopeAdminUser($org);
    app(TenantResolver::class)->setOrgId($org->id);

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $fv = FrameworkVersion::factory()->create([
        'organization_id' => $org->id,
        'revision_id' => $baseline->id,
    ]);

    $baselineIco = Role::where('revision_id', $baseline->id)->where('code', 'ICO')->firstOrFail();
    $competencyId = DB::table('framework_role_competency')
        ->where('role_id', $baselineIco->id)
        ->value('competency_id');

    // The seeder never authors default questions for the baseline
    // (`ApplyCompetencySelection::copyDefaults()`'s own docblock: "every
    // pre-existing FrameworkVersion is pinned to the baseline, which is
    // never authored with defaults") — authored directly here.
    $default = FrameworkDefaultQuestion::factory()->create([
        'revision_id' => $baseline->id,
        'competency_id' => $competencyId,
        'position' => 0,
    ]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    // Attached DIRECTLY, bypassing ApplyCompetencySelection — simulating a
    // competency selected before this write path existed, the same
    // scenario `BackfillProjectQuestionsCommand` exists to repair
    // platform-wide.
    $project->competencies()->attach([$competencyId => ['position' => 0]]);

    expect(ProjectQuestion::where('project_id', $project->id)->where('competency_id', $competencyId)->exists())
        ->toBeFalse();

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['competency_ids' => [$competencyId]])
        ->assertOk();

    $question = ProjectQuestion::where('project_id', $project->id)->where('competency_id', $competencyId)->first();

    expect($question)->not->toBeNull();
    // ProjectQuestion.text is a plain `array` cast (not HasTranslations, per
    // App\Models\ProjectQuestion::casts()); ApplyCompetencySelection::
    // copyDefaults() writes $default->getTranslations('text') into it as-is.
    expect($question->text)->toBe($default->getTranslations('text'));
});
