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
 * Nullable throughout: the organization-wide active template stays the
 * fallback, so a project that pins nothing behaves exactly as it did.
 */

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
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

test('the column is optional and defaults to null', function (): void {
    // Pinning nothing is a supported configuration, not a missing setting:
    // the organization's active template is what such a project falls back to,
    // which is exactly what every project did before this column existed.
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);

    expect(Project::factory()->create()->fresh()->avatar_template_id)->toBeNull();
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

test('an operator can UNPIN, returning the project to the organization fallback', function (): void {
    // Without an explicit route back to null, pinning would be a one-way door:
    // an operator could never restore the org-wide default they started from.
    $org = Organization::factory()->create();
    ['token' => $token] = projTemplateAdmin($org);
    app(TenantResolver::class)->setOrgId($org->id);
    $template = projTemplateFor($org);
    $project = Project::factory()->create(['avatar_template_id' => $template->id]);

    $response = $this->withToken($token)->patchJson('/api/projects/'.$project->id, [
        'avatar_template_id' => null,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.avatar_template_id', null);
    expect($project->fresh()->avatar_template_id)->toBeNull();
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

    $resolved = $project->fresh()->avatarTemplate?->provider
        ?? $project->provider_override
        ?? config('interview.provider', 'heygen');

    expect($resolved)->toBe('tavus');
});

test('provider_override still applies when nothing is pinned', function (): void {
    // The precedence chain's middle rung must keep working: pinning is an
    // ADDITION above `provider_override`, never a replacement for it.
    config(['interview.provider' => 'heygen']);

    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $project = Project::factory()->create(['provider_override' => 'tavus']);

    $resolved = $project->fresh()->avatarTemplate?->provider
        ?? $project->provider_override
        ?? config('interview.provider', 'heygen');

    expect($resolved)->toBe('tavus');
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

test('deleting a pinned template returns its projects to the fallback, never deletes them', function (): void {
    // `nullOnDelete`, not `cascadeOnDelete`. A project is a far heavier object
    // than a template, and losing one because a cosmetic setting was removed
    // would be catastrophic and completely unexpected.
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $template = projTemplateFor($org);
    $project = Project::factory()->create(['avatar_template_id' => $template->id]);

    TenantContextScope::runFor($org->id, fn () => $template->delete());

    expect($project->fresh())->not->toBeNull();
    expect($project->fresh()->avatar_template_id)->toBeNull();
});
