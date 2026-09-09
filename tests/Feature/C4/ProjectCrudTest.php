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

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
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
        'avatar_template_id' => templateIdForCurrentOrg(),
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
    // ARCHIVED: deletion is gated on the terminal state, and this case is
    // about the soft-delete mechanics, not about the gate.
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'archived',
    ]);

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

/**
 * A live project is not deletable; a draft is.
 *
 * Deleting a `draft` costs nothing — nobody has been interviewed under it.
 * The same button on an `active` project takes a live assessment away from
 * candidates mid-interview, and no confirmation dialog makes that
 * recoverable.
 */
test('a draft can still be deleted — it is not a trap', function (): void {
    // Demanding `archived` would have made this impossible: the approved
    // transitions are draft->active and active->archived, so the only route
    // out of a mistyped draft would have been to publish it to candidates.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $draft = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
    ]);

    $this->withToken($token)->deleteJson("/api/projects/{$draft->id}")->assertNoContent();
});
test('an ACTIVE project cannot be deleted, whatever the role', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // `active` only. Refusing `draft` too would trap every mistyped draft
    // permanently: the approved transitions are draft->active and
    // active->archived, so the one way out would be to publish it to
    // candidates first.
    foreach (['active'] as $status) {
        $project = Project::factory()->create([
            'framework_version_id' => $fv->id,
            'status' => $status,
        ]);

        // 409, not 403: the admin IS allowed to delete projects. Telling them
        // otherwise sends them asking for a role they already hold.
        $response = $this->withToken($token)->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(409);
        expect($response->json('error'))->toBe('project_is_active');
        expect(Project::withTrashed()->find($project->id)->deleted_at)->toBeNull();
    }
});

test('a superadmin may delete an archived project of any organization', function (): void {
    // `Gate::before` short-circuits the role half; the STATUS half is a
    // property of the project, checked outside the policy for that reason.
    $org = Organization::factory()->create();
    $superadmin = User::factory()->create(['is_superadmin' => true, 'organization_id' => $org->id]);
    $token = auth('api')->login($superadmin);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $archived = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'archived',
    ]);
    $active = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'active',
    ]);

    $this->withToken($token)->deleteJson("/api/projects/{$archived->id}")->assertNoContent();

    // The state rule holds for a superadmin too, which is exactly what a
    // policy could not deliver: `Gate::before` short-circuits every policy
    // method, so an invariant written there is one every superadmin skips.
    $this->withToken($token)->deleteJson("/api/projects/{$active->id}")->assertStatus(409);
});

test('can.delete tells the truth about the state, not just the role', function (): void {
    // The flag draws a button. A button that is always answered with a 409 is
    // worse than one that is not offered — and the policy cannot carry this
    // half, because `Gate::before` short-circuits it for superadmins.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    foreach (['draft' => true, 'active' => false, 'archived' => true] as $status => $expected) {
        $project = Project::factory()->create([
            'framework_version_id' => $fv->id,
            'status' => $status,
        ]);

        expect(
            $this->withToken($token)
                ->getJson("/api/projects/{$project->id}")
                ->json('data.can.delete')
        )->toBe($expected, "can.delete for a {$status} project");
    }
});

test('a non-list competency_ids payload is refused, not 500', function (): void {
    // `position` comes from the ARRAY KEY. `{"a": 12}` passed validation —
    // which only checked the VALUES — and sent the string 'a' into
    // `project_competencies.position`, an unsignedInteger column. A 500 where
    // a 422 belongs.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $competency = Competency::query()->where('type', 'standard')->firstOrFail();

    $response = $this->withToken($token)->postJson(
        '/api/projects',
        array_merge(crudStandardPayload($fv->id), ['competency_ids' => ['a' => $competency->id]])
    );

    $response->assertUnprocessable();
    // The RULE that refused it, not merely "a 422" — the payload is otherwise
    // complete, so anything else failing would be a different bug passing.
    expect($response->json('errors'))->toHaveKey('competency_ids');
});

test('duplicate competency_ids are refused rather than silently collapsed', function (): void {
    // `$attach[$id]` is overwritten by a repeat, so three requested
    // competencies became two — at positions 1 and 2, with position 0 gone.
    // Silently, and only visible later as an interview shorter than the
    // project says it is.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $ids = Competency::query()->where('type', 'standard')->limit(2)->pluck('id')->all();

    $response = $this->withToken($token)->postJson(
        '/api/projects',
        array_merge(crudStandardPayload($fv->id), [
            'competency_ids' => [$ids[0], $ids[0], $ids[1]],
        ])
    );

    $response->assertUnprocessable();
    expect(array_keys($response->json('errors')))->toContain('competency_ids.1');
});

test('a well-formed distinct list is still accepted', function (): void {
    // The control for both cases above: rules that refused every payload
    // would look identical.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $ids = Competency::query()->where('type', 'standard')->limit(2)->pluck('id')->all();

    $this->withToken($token)->postJson(
        '/api/projects',
        array_merge(crudStandardPayload($fv->id), ['competency_ids' => $ids])
    )->assertCreated();
});

/**
 * PATCH enforced none of the composition invariants POST enforces.
 *
 * `role_code` was `['nullable', 'string']` and the competencies were
 * unchecked, so on a DRAFT — where the immutability gate does not apply — a
 * PATCH could set a role that does not exist, or hang `potential`
 * competencies off a `standard` project, and get a 200. It surfaced far away
 * and much later, as an interview that could not compose.
 */
test('PATCH refuses a role_code that is not one of the five', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    // The SPECIFIC rule, not "a 422". Any rule at all satisfies a bare status
    // assertion — the immutability gate, a slug rule, anything — and the
    // whole point is that this one now fires.
    $response = $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['role_code' => 'NOT_A_ROLE'])
        ->assertUnprocessable();

    expect($response->json('errors.role_code.0'))->toBe('role_invalid');
    expect($project->fresh()->role_code)->toBe('ICO');
});

test('PATCH refuses a competency that does not belong to the role', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    // A `potential` competency on a `standard` project — the type invariant
    // this endpoint never checked.
    $potential = Competency::query()->where('type', 'potential')->firstOrFail();

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['competency_ids' => [$potential->id]])
        ->assertUnprocessable();
});

test('PATCH still accepts a legal composition change', function (): void {
    // The control: rules that refused every PATCH would look identical.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $role = Role::where('code', 'ICO')->firstOrFail();
    $ids = DB::table('framework_role_competency')
        ->where('role_id', $role->id)
        ->limit(2)
        ->pluck('competency_id')
        ->all();

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['competency_ids' => $ids])
        ->assertOk();

    expect($project->fresh()->competencies()->count())->toBe(2);
});

test('a competency id that does not exist is a 422, not a foreign-key 500', function (): void {
    // `validateStandard` iterates `whereIn(...)->get()`, so an id that is NOT
    // FOUND is never looped over and no cross-field rule can see it. It went
    // straight into attach()/sync() and hit the foreign key.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)->postJson(
        '/api/projects',
        array_merge(crudStandardPayload($fv->id), ['competency_ids' => [999_999]])
    )->assertUnprocessable();

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['competency_ids' => [999_999]])
        ->assertUnprocessable();
});

/**
 * A PATCH that changes the role without resubmitting the competencies.
 *
 * The composition check received only the SUBMITTED ids, so both branches
 * skipped their loop on `if (! empty($competencyIds))` when none were sent —
 * the role changed, the competencies did not, and nothing compared them.
 * The guard looked enforced and was not.
 */
test('changing the role revalidates the competencies already attached', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'FLL',
    ]);

    $fll = Role::where('code', 'FLL')->firstOrFail();
    $ico = Role::where('code', 'ICO')->firstOrFail();

    $icoIds = DB::table('framework_role_competency')->where('role_id', $ico->id)->pluck('competency_id');
    $fllOnly = DB::table('framework_role_competency')
        ->where('role_id', $fll->id)
        ->whereNotIn('competency_id', $icoIds)
        ->value('competency_id');

    expect($fllOnly)->not->toBeNull('the catalogue must have an FLL-only competency for this case');

    $project->competencies()->attach([$fllOnly => ['position' => 0]]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['role_code' => 'ICO'])
        ->assertUnprocessable();

    expect($project->fresh()->role_code)->toBe('FLL');
});

test('switching to potential revalidates the standard competencies already attached', function (): void {
    // The worse direction: a `standard` competency on a `potential` project
    // violates a binding domain constraint, and it used to arrive via a 200.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $standard = Competency::query()->where('type', 'standard')->firstOrFail();
    $project->competencies()->attach([$standard->id => ['position' => 0]]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", [
            'assessment_type' => 'potential',
            'role_code' => null,
        ])
        ->assertUnprocessable();

    expect($project->fresh()->assessment_type)->toBe('standard');
});

test('a PATCH that touches neither role nor competencies is still accepted', function (): void {
    // The control. Revalidating the stored set on every PATCH must not turn
    // a rename into a 422.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $role = Role::where('code', 'ICO')->firstOrFail();
    $id = DB::table('framework_role_competency')->where('role_id', $role->id)->value('competency_id');
    $project->competencies()->attach([$id => ['position' => 0]]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertOk();
});

/**
 * A project whose composition is already invalid must still accept a rename.
 *
 * PATCH used to accept `role_code: "NOT_A_ROLE"` with a 200, and such a
 * project could then be promoted to `active` with no composition check on
 * that path. Revalidating on EVERY patch bricks it: the rename fails on the
 * composition gate, and fixing `role_code` fails on the immutability gate.
 * No way out in either direction.
 */
test('a project with a legacy-invalid role can still be renamed', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // Written straight to the database, as the old endpoint allowed.
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'NOT_A_ROLE',
    ]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertOk();

    expect($project->fresh()->name)->toBe('Renamed');
});

test('error_redirect_url round-trips, like every other writable setting', function (): void {
    // Both FormRequests accept it and `$fillable` carries it, but no
    // admin-facing response gave it back — so the edit form could not render
    // what was configured. `ParticipantResource` exposes it to the CANDIDATE
    // for error recovery, which is a different consumer.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $created = $this->withToken($token)->postJson('/api/projects', array_merge(
        crudStandardPayload($fv->id),
        ['error_redirect_url' => 'https://example.test/oops']
    ))->assertCreated();

    expect($created->json('data.error_redirect_url'))->toBe('https://example.test/oops');

    expect(
        $this->withToken($token)
            ->getJson("/api/projects/{$created->json('data.id')}")
            ->json('data.error_redirect_url')
    )->toBe('https://example.test/oops');
});

test('a soft-deleted avatar template cannot be pinned', function (): void {
    // `Rule::exists` is a raw query-builder rule and does not apply the
    // SoftDeletes scope. An unused template deletes fine — the model's
    // `deleting` guard only refuses when a project points at it — so a trashed
    // template could be pinned, the resolver would find nothing, and the
    // configured provider would decide silently: the exact defect this column
    // was made required to remove.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $template = AvatarTemplate::findOrFail(templateIdForCurrentOrg());
    $template->delete();

    $this->withToken($token)->postJson('/api/projects', array_merge(
        crudStandardPayload($fv->id),
        ['avatar_template_id' => $template->id]
    ))->assertUnprocessable();
});

/**
 * DERIVED from the declared rules, not hand-picked.
 *
 * The first version of this test listed six payloads it thought interesting,
 * and that is exactly why `exit_redirect_url.string` shipped unmapped: no
 * case sent a non-string to it. Reading `rules()` means a rule added tomorrow
 * without a code fails here tomorrow.
 */
test('EVERY declared rule on both project requests carries a code', function (): void {
    // `rules()` reads the caller's `organization_id` to scope its `exists`
    // rules, so the requests need a user behind them.
    $org = Organization::factory()->create();
    ['user' => $user] = crudAdminUser($org);
    $this->actingAs($user);

    // Collected rather than asserted one by one: the failure message should
    // name EVERY unmapped rule at once, not the first one found.
    $missing = [];

    foreach ([new StoreProjectRequest, new UpdateProjectRequest] as $request) {
        $request->setUserResolver(fn () => $user);

        // POPULATED, not empty. `UpdateProjectRequest::rules()` adds
        // `framework_version_id => ['prohibited']` only when that key is
        // PRESENT, so an empty request never declares it — and the one field
        // this API calls immutable from creation was the single field whose
        // code could be deleted with every test still green.
        $request->replace([
            'framework_version_id' => 1,
            'slug' => 'x',
            'name' => 'x',
            'assessment_type' => 'standard',
            'role_code' => 'ICO',
            'language' => 'it',
            'status' => 'draft',
            'competency_ids' => [],
            'avatar_template_id' => 1,
            'webhook_url' => 'https://example.test',
            'webhook_secret' => 'x',
            'webhook_events' => [],
            'exit_redirect_url' => 'https://example.test',
            'error_redirect_url' => 'https://example.test',
            'pause_every_n_competencies' => 1,
            'nudge_min_chars' => 1,
            'deadline_at' => '2026-01-01',
            'goes_live_at' => '2026-01-01',
        ]);

        $messages = $request->messages();

        foreach ($request->rules() as $field => $rules) {
            foreach ((is_array($rules) ? $rules : explode('|', (string) $rules)) as $rule) {
                // OBJECT rules count too. `Rule::unique(...)`, `Rule::exists(...)`
                // and `Rule::in(...)` are instances, not strings, and skipping
                // them left `slug.unique` — the duplicate-slug refusal an
                // operator actually meets — outside this guard entirely. The
                // class basename IS the rule name Laravel resolves messages by.
                $name = is_string($rule)
                    ? (str_contains($rule, ':') ? strstr($rule, ':', true) : $rule)
                    : strtolower(class_basename($rule));

                // Rules that cannot fail with a message of their own.
                if (in_array($name, ['sometimes', 'nullable', 'bail'], true)) {
                    continue;
                }

                $key = "{$field}.{$name}";

                if (! array_key_exists($key, $messages)) {
                    $missing[] = $request::class."::{$key}";
                }
            }
        }
    }

    expect($missing)->toBe([], 'these rules answer with English prose: '.implode(', ', $missing));
});

test('the project endpoints answer shape rules with codes, never prose', function (): void {
    // An Italian operator creating a project with a duplicate slug read "The
    // name has already been taken." under an Italian label. The COMPOSITION
    // refusals stay authored sentences on purpose — they name the competency
    // and the role that clash, which is the only part that says what to
    // change — so this asserts the shape rules only.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)->postJson('/api/projects', crudStandardPayload($fv->id))
        ->assertCreated();

    $cases = [
        ['slug' => str_repeat('a', 300)],
        ['name' => ''],
        ['assessment_type' => 'nonsense'],
        ['language' => 'zz'],
        ['webhook_url' => 'not-a-url'],
        ['avatar_template_id' => 999_999],
        // Non-STRINGS, which the first version of this list forgot — and
        // which is how `.string` shipped unmapped on all three url fields.
        ['exit_redirect_url' => 123],
        ['error_redirect_url' => ['an', 'array']],
        ['webhook_url' => 123],
    ];

    // The name says "endpoints", plural. It called only POST, so
    // `UpdateProjectRequest::messages()` could have been deleted whole and
    // both cases would still have passed.
    $existing = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    foreach ($cases as $override) {
        foreach (['post', 'patch'] as $verb) {
            $errors = $verb === 'post'
                ? $this->withToken($token)
                    ->postJson('/api/projects', array_merge(crudStandardPayload($fv->id), $override))
                    ->assertUnprocessable()
                    ->json('errors')
                : $this->withToken($token)
                    ->patchJson("/api/projects/{$existing->id}", $override)
                    ->assertUnprocessable()
                    ->json('errors');

            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    expect($message)->toMatch('/\A[a-z][a-z0-9_]*\z/', "{$verb} {$field} answered with prose: {$message}");
                }
            }
        }
    }
});

test('a duplicate slug answers slug_taken, by name', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $payload = crudStandardPayload($fv->id);

    $this->withToken($token)->postJson('/api/projects', $payload)->assertCreated();

    $response = $this->withToken($token)->postJson('/api/projects', $payload)->assertUnprocessable();

    expect($response->json('errors.slug.0'))->toBe('slug_taken');
});

/**
 * The three refusals `withValidator` composes by hand.
 *
 * They are the ones the message map cannot cover, which makes them exactly
 * the ones a `messages()`-derived test cannot see — and that is how
 * `assessment_type_immutable` shipped answering for a role change.
 */
test('the immutability refusal lands on the field that actually moved', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $roleChange = $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['role_code' => 'FLL'])
        ->assertUnprocessable();

    expect($roleChange->json('errors.role_code.0'))->toBe('role_code_immutable')
        // The operator never touched this field. Naming it sends them to
        // revert something they did not change.
        ->and($roleChange->json('errors.assessment_type'))->toBeNull();

    $typeChange = $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['assessment_type' => 'potential'])
        ->assertUnprocessable();

    expect($typeChange->json('errors.assessment_type.0'))->toBe('assessment_type_immutable');
});

test('a forbidden status transition answers with a code', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    // active -> draft is not an approved transition.
    $response = $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['status' => 'draft'])
        ->assertUnprocessable();

    expect($response->json('errors.status.0'))->toBe('status_transition_forbidden');
    expect($project->fresh()->status)->toBe('active');
});

test('an approved transition is still accepted', function (): void {
    // The control: a guard that refused every transition would look identical.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['status' => 'active'])
        ->assertOk();

    expect($project->fresh()->status)->toBe('active');
});

test('the framework pin is refused on PATCH with a code, not with prose', function (): void {
    // The loudest docblock in the request file, and the only field whose
    // code an empty-request guard could not see.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $other = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $response = $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['framework_version_id' => $other->id])
        ->assertUnprocessable();

    expect($response->json('errors.framework_version_id.0'))->toBe('framework_version_immutable');
    expect($project->fresh()->framework_version_id)->toBe($fv->id);
});

test('a potential project refuses a role_code with a code, not a sentence', function (): void {
    // `role_code must be null for potential assessment type.` named no
    // competency and no role, so the composition carve-out never covered it —
    // it was simply English prose reaching an Italian operator.
    $org = Organization::factory()->create();
    ['token' => $token] = crudAdminUser($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'draft',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
    ]);

    $response = $this->withToken($token)
        ->patchJson("/api/projects/{$project->id}", ['assessment_type' => 'potential'])
        ->assertUnprocessable();

    expect($response->json('errors.role_code.0'))->toBe('role_code_must_be_null');
});
