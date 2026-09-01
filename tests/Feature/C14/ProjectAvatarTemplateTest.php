<?php

declare(strict_types=1);

/**
 * projects.avatar_template_id — which avatar template THIS project runs on.
 *
 * Before this column a project had no say. The provider came from
 * `provider_override` or the `INTERVIEW_PROVIDER` env default, and
 * `ActiveTemplateResolver` returned the organization's ONE active template for
 * that provider. Two projects on the same provider could never use different
 * templates, and an operator holding one active HeyGen template and one active
 * Tavus template — a legal state, since activation is scoped per provider —
 * had no way to say which one a given project used. The `INTERVIEW_PROVIDER`
 * default silently decided for them.
 *
 * REQUIRED, and it did not start that way. It shipped nullable with the
 * organization-wide active template as the fallback, so no existing project had
 * to change — but that fallback is precisely what let the configuration choose
 * instead of the project, which is the defect the column was added to fix. It
 * is now NOT NULL, backfilled from what the fallback would have resolved for
 * each project.
 *
 * Two consequences, both real behaviour changes rather than bookkeeping:
 *
 *   - The FK is `restrictOnDelete`, forced by NOT NULL — `nullOnDelete` cannot
 *     null a NOT NULL column. Deleting a template a project uses is refused.
 *   - `provider_override` is now UNREACHABLE. It sat below the template in the
 *     precedence chain, and nothing can pin nothing any more.
 */

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{user: User, token: string}
 */
function projTemplateAdmin(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

function projTemplateFor(Organization $org, string $provider = 'heygen'): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Template '.uniqid(),
        'provider' => $provider,
        'config' => [],
        'is_active' => false,
    ]));
}

/**
 * @return array<string, mixed>
 */
function projTemplatePayload(int $fvId): array
{
    return [
        'framework_version_id' => $fvId,
        'slug' => 'tpl-proj-'.uniqid(),
        'name' => 'Template Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
    ];
}

function projTemplateFramework(Organization $org): FrameworkVersion
{
    app(TenantResolver::class)->setOrgId($org->id);

    return FrameworkVersion::factory()->create(['organization_id' => $org->id]);
}

// ─── Persistence and default ─────────────────────────────────────────────────

test('the column is REQUIRED — a project always names the template it runs on', function (): void {
    // It shipped nullable, with the organization's active template as the
    // fallback. That fallback is exactly what let the configuration choose
    // silently instead of the project, which is the defect the column was added
    // to fix, so the fallback is gone and the column is NOT NULL.
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);

    expect(Project::factory()->create()->fresh()->avatar_template_id)->not->toBeNull();
});

test('the constraint lives in the SCHEMA, not only in a FormRequest', function (): void {
    // "Every project has a template" has to be true for the portability import
    // path and any future writer, not only for requests that happen to go
    // through validation — so it is a NOT NULL column, and this reads the
    // catalogue to prove it.
    //
    // Asserted by INSPECTION rather than by provoking a violation: in Postgres
    // a failed statement aborts the surrounding transaction, and
    // `RefreshDatabase` wraps each test in one, so a deliberate constraint
    // breach leaves the connection unusable for the rest of the test and
    // destabilises its rollback.
    $nullable = DB::selectOne(
        "select is_nullable from information_schema.columns
         where table_name = 'projects' and column_name = 'avatar_template_id'"
    );

    expect($nullable?->is_nullable)->toBe('NO');

    // And the FK is RESTRICT, not the `nullOnDelete` it shipped with — the two
    // are mutually exclusive once the column is NOT NULL.
    $rule = DB::selectOne(
        "select rc.delete_rule
         from information_schema.referential_constraints rc
         join information_schema.table_constraints tc
           on tc.constraint_name = rc.constraint_name
         where tc.table_name = 'projects'
           and tc.constraint_name = 'projects_avatar_template_id_foreign'"
    );

    expect($rule?->delete_rule)->toBe('RESTRICT');
});

// ─── Write surface ───────────────────────────────────────────────────────────

test('an operator can pin a template on create, and read it back', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    $fv = projTemplateFramework($org);
    $template = projTemplateFor($org);

    $response = $this->withToken($token)->postJson('/api/projects', array_merge(
        projTemplatePayload($fv->id),
        ['avatar_template_id' => $template->id],
    ));

    $response->assertCreated();
    $response->assertJsonPath('data.avatar_template_id', $template->id);
});

test('an operator can pin a template on update', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);
    $project = Project::factory()->create();
    $template = projTemplateFor($org);

    $response = $this->withToken($token)->patchJson('/api/projects/'.$project->id, [
        'avatar_template_id' => $template->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.avatar_template_id', $template->id);
});

test('UNPINNING is refused — there is nothing to unpin to any more', function (): void {
    // The mirror image of the earlier behaviour, and deliberately so. While the
    // column was nullable, an explicit null was a legal unpin back to the
    // organization fallback. That fallback is gone, so a null would leave the
    // project with no template — and its interviews running on whatever the
    // configuration happened to say, which is the defect this column exists to
    // remove. `sometimes` WITHOUT `nullable` is what encodes that: a PATCH may
    // omit the field, but it may not empty it.
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);
    $template = projTemplateFor($org);
    $project = Project::factory()->create(['avatar_template_id' => $template->id]);

    $response = $this->withToken($token)->patchJson('/api/projects/'.$project->id, [
        'avatar_template_id' => null,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['avatar_template_id']);
    expect($project->fresh()->avatar_template_id)->toBe($template->id);
});

test('a PATCH that does not mention the template leaves it alone', function (): void {
    // `sometimes` still has to mean "untouched", or every unrelated edit would
    // demand the operator restate the binding.
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);
    $template = projTemplateFor($org);
    $project = Project::factory()->create(['avatar_template_id' => $template->id]);

    $response = $this->withToken($token)->patchJson('/api/projects/'.$project->id, [
        'name' => 'Renamed, nothing else',
    ]);

    $response->assertOk();
    expect($project->fresh()->avatar_template_id)->toBe($template->id);
});

test('creating a project without a template is refused', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    $fv = projTemplateFramework($org);

    $response = $this->withToken($token)->postJson('/api/projects', projTemplatePayload($fv->id));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['avatar_template_id']);
});

// ─── Tenancy ─────────────────────────────────────────────────────────────────

test("another organization's template cannot be pinned", function (): void {
    // The tenant boundary is enforced at VALIDATION, not merely at read time.
    // Relying on the resolver to ignore a foreign pin would leave the id
    // sitting in our row — a cross-tenant reference persisted in the database,
    // which is exactly what tenant isolation forbids regardless of whether
    // anything later reads it.
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($mine);
    $fv = projTemplateFramework($mine);
    $foreign = projTemplateFor($theirs);

    app(TenantResolver::class)->setOrgId($mine->id);

    $response = $this->withToken($token)->postJson('/api/projects', array_merge(
        projTemplatePayload($fv->id),
        ['avatar_template_id' => $foreign->id],
    ));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['avatar_template_id']);
});

test('a template id that does not exist is rejected', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    $fv = projTemplateFramework($org);

    $response = $this->withToken($token)->postJson('/api/projects', array_merge(
        projTemplatePayload($fv->id),
        ['avatar_template_id' => 999_999],
    ));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['avatar_template_id']);
});

// ─── The pin actually drives the interview ───────────────────────────────────

/**
 * Mirrors `InterviewController`'s precedence chain (`:198`).
 *
 * Stated plainly: this is a RE-IMPLEMENTATION, so it verifies the DATA the
 * chain reads, not the controller line itself. Exercising that line for real
 * needs a whole `/start` round-trip — a participant, an SSO token, a framework
 * pin, a live provider double — which is `InterviewStartTest`'s job, not this
 * file's. What is asserted here is the part that was actually broken: that a
 * pinned template is reachable and outranks both fallbacks.
 *
 * The resolver tests are the real behavioural coverage, since
 * `ActiveTemplateResolver` is what every consumer (both providers, the live
 * clock, the LLM snapshot) goes through.
 */
function projTemplateResolvedProvider(Project $project): string
{
    $pinned = $project->avatar_template_id === null
        ? null
        : AvatarTemplate::whereKey($project->avatar_template_id)->value('provider');

    return $pinned
        ?? $project->provider_override
        ?? config('interview.provider', 'heygen');
}

test('a pinned template decides the provider, overriding the env default', function (): void {
    // The whole point. `INTERVIEW_PROVIDER` used to decide for an operator who
    // held one active HeyGen template and one active Tavus template — a legal
    // state, since activation is scoped per provider. Pinning a Tavus template
    // on a project running under a `heygen` default must now win, or "choose a
    // template per project" would be a setting that changes nothing.
    config(['interview.provider' => 'heygen']);

    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);

    $tavusTemplate = projTemplateFor($org, 'tavus');
    $project = Project::factory()->create(['avatar_template_id' => $tavusTemplate->id]);

    expect(projTemplateResolvedProvider($project->fresh()))->toBe('tavus');
});

test('the pinned template outranks provider_override, which is now unreachable', function (): void {
    // A CONSEQUENCE of making the template required, recorded rather than left
    // to be rediscovered. `provider_override` used to be the middle rung of the
    // precedence chain and applied whenever a project pinned nothing. Nothing
    // can pin nothing any more, so that rung can never be reached: the template
    // always decides.
    //
    // The column is deliberately NOT removed here. It predates this change
    // (C7a), the migration below reads it while backfilling, and deleting a
    // column to tidy a precedence chain is a separate decision with its own
    // migration. What must not happen is a test asserting behaviour the schema
    // has made impossible — that is a test that can only ever pass by accident.
    config(['interview.provider' => 'heygen']);

    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);

    $heygenTemplate = projTemplateFor($org, 'heygen');
    $project = Project::factory()->create([
        'avatar_template_id' => $heygenTemplate->id,
        'provider_override' => 'tavus',
    ]);

    // The override says tavus, the template says heygen, and the template wins.
    expect(projTemplateResolvedProvider($project->fresh()))->toBe('heygen');
});

test('two projects on the same provider can run different templates', function (): void {
    // Impossible before this column: only one template per provider can be
    // `is_active`, so every project on that provider shared it.
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);

    $first = projTemplateFor($org, 'heygen');
    $second = projTemplateFor($org, 'heygen');

    $projectA = Project::factory()->create(['avatar_template_id' => $first->id]);
    $projectB = Project::factory()->create(['avatar_template_id' => $second->id]);

    $resolver = app(ActiveTemplateResolver::class);

    $forA = TenantContextScope::runFor($org->id, fn () => $resolver->resolve('heygen', $projectA->id));
    $forB = TenantContextScope::runFor($org->id, fn () => $resolver->resolve('heygen', $projectB->id));

    expect($forA?->id)->toBe($first->id);
    expect($forB?->id)->toBe($second->id);
    expect($forA?->id)->not->toBe($forB?->id);
});

// ─── Deleting the template must not delete the project ───────────────────────

test('deleting a template a project uses is REFUSED, never cascaded', function (): void {
    // `restrictOnDelete`, forced by NOT NULL: the two earlier options are both
    // gone. `nullOnDelete` cannot null a NOT NULL column, and `cascadeOnDelete`
    // would delete PROJECTS because a cosmetic setting was removed — a project
    // is a far heavier object than a template, and losing one that way would be
    // catastrophic and completely unexpected.
    //
    // Asserted at the database, so it holds for any writer, not only the
    // controller that returns the friendly 409.
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $template = projTemplateFor($org);
    $project = Project::factory()->create(['avatar_template_id' => $template->id]);

    // Only the throw is asserted, and that is a Postgres constraint rather than
    // a shortcut: a failed statement ABORTS the surrounding transaction, and
    // `RefreshDatabase` wraps each test in one — so any `$project->fresh()`
    // after this point fails with "current transaction is aborted" and would
    // report a false negative about the project's survival. That the project
    // survives is what `restrictOnDelete` MEANS (the delete never happens), and
    // the API-level test below observes it outside an aborted transaction.
    expect($project->exists)->toBeTrue();

    expect(fn () => TenantContextScope::runFor($org->id, fn () => $template->delete()))
        ->toThrow(QueryException::class);
});

test('the API refuses that deletion with a readable conflict, not a 500', function (): void {
    // Without this guard the FK violation surfaces as an unhandled exception —
    // a 500 that tells the operator nothing about what to do. The count is
    // included so they know how much reassigning is involved before starting.
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);
    $template = projTemplateFor($org);
    Project::factory()->create(['avatar_template_id' => $template->id]);

    $response = $this->withToken($token)->deleteJson('/api/avatar-templates/'.$template->id);

    $response->assertConflict();
    $response->assertJsonPath('error', 'template_in_use');
    $response->assertJsonPath('project_count', 1);
});

// ─── Deactivation ─────────────────────────────────────────────────────────────

/**
 * An admin can take a template out of service without deleting it.
 *
 * Only `activate` existed, so the only way to stop offering a template was to
 * activate a different one — which needs a different one to exist — or to
 * delete it, which is destructive and now refused outright while any project
 * pins it.
 *
 * What deactivation means changed with the mandatory pin, and for the better.
 * `is_active` used to be the org-wide FALLBACK, so switching it off silently
 * changed which template every unpinned project ran on. Every project now names
 * its own, so `is_active` is just "the one offered as the default for new
 * projects" — and turning it off is a safe, reversible bookkeeping act rather
 * than a live reconfiguration.
 */
test('an admin can deactivate a template', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Active one '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'is_active' => true,
    ]));

    $response = $this->withToken($token)
        ->postJson('/api/avatar-templates/'.$template->id.'/deactivate');

    $response->assertOk();
    $response->assertJsonPath('data.is_active', false);
    expect($template->fresh()->is_active)->toBeFalse();
});

test('deactivating leaves the projects that pin it running on it', function (): void {
    // The whole reason this is safe now. A pinned template does not have to be
    // active — `ActiveTemplateResolver` returns what the project pinned — so
    // taking it out of the default list must not disturb anything live.
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Pinned and active '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'is_active' => true,
    ]));
    $project = Project::factory()->create(['avatar_template_id' => $template->id]);

    $this->withToken($token)
        ->postJson('/api/avatar-templates/'.$template->id.'/deactivate')
        ->assertOk();

    expect($project->fresh()->avatar_template_id)->toBe($template->id);

    $resolved = TenantContextScope::runFor(
        $org->id,
        fn () => app(ActiveTemplateResolver::class)->resolve('heygen', $project->id),
    );

    expect($resolved?->id)->toBe($template->id);
});

test('deactivating an already-inactive template is a no-op, not an error', function (): void {
    // Idempotent on purpose: an operator double-clicking, or two of them acting
    // at once, must not see a failure for a state that is already correct.
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);
    $template = projTemplateFor($org); // created with is_active = false

    $this->withToken($token)
        ->postJson('/api/avatar-templates/'.$template->id.'/deactivate')
        ->assertOk();

    expect($template->fresh()->is_active)->toBeFalse();
});

test("another organization's template cannot be deactivated", function (): void {
    // A 404, never a 403: the tenant scope means the row is not found at all,
    // and a 403 would confirm the id exists.
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($mine);
    $foreign = projTemplateFor($theirs);

    $this->withToken($token)
        ->postJson('/api/avatar-templates/'.$foreign->id.'/deactivate')
        ->assertNotFound();
});
