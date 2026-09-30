<?php

declare(strict_types=1);

/**
 * The superadmin platform surface for global avatar templates (A3):
 * `api/admin/avatar-templates` list, show, create and update.
 *
 * Every route serves a bare superadmin AND one acting as an organization —
 * the platform context is explicit, the acting organization is never stamped —
 * and answers 403 to every other principal, 401 to nobody.
 */

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

dataset('patSuperadmins', ['acting', 'bare']);
dataset('patDenied', ['admin', 'operator', 'viewer', 'no_role', 'cross_tenant']);

function patToken(string $actor, Organization $org): string
{
    if ($actor === 'no_role') {
        return auth('api')->login(User::factory()->create(['organization_id' => $org->id]));
    }

    if ($actor === 'cross_tenant') {
        return TemplateActors::token('admin', Organization::factory()->create());
    }

    return TemplateActors::token($actor, $org);
}

function patPin(Organization $org, AvatarTemplate $template, int $count = 1): void
{
    TenantContextScope::runFor($org->id, function () use ($org, $template, $count): void {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

        Project::factory()->count($count)->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
            'avatar_template_id' => $template->id,
        ]);
    });
}

// ─── list and show ───────────────────────────────────────────────────────────

test('the list carries every platform template, retired ones included, with usage and never an organization row', function (string $actor): void {
    $org = Organization::factory()->create();
    $offered = PlatformTemplates::insertActiveGlobal(['name' => 'Offered']);
    $retired = PlatformTemplates::insertGlobal(['name' => 'Retired']);
    $own = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Own', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));
    patPin($org, $offered, 2);

    $response = $this->withToken(patToken($actor, $org))->getJson('/api/admin/avatar-templates')->assertOk();

    $byId = collect($response->json('data'))->keyBy('id');

    expect($byId->keys()->sort()->values()->all())->toBe(collect([$offered->id, $retired->id])->sort()->values()->all())
        ->and($byId->has($own->id))->toBeFalse()
        ->and($byId[$offered->id]['scope'])->toBe('platform')
        ->and($byId[$offered->id]['is_active'])->toBeTrue()
        ->and($byId[$offered->id]['usage'])->toBe(['organization_count' => 1, 'project_count' => 2])
        ->and($byId[$retired->id]['is_active'])->toBeFalse()
        ->and($byId[$retired->id]['usage'])->toBe(['organization_count' => 0, 'project_count' => 0]);
})->with('patSuperadmins');

test('an empty platform list is an empty collection', function (): void {
    $this->withToken(patToken('bare', Organization::factory()->create()))
        ->getJson('/api/admin/avatar-templates')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('show returns one platform template with its usage, zero when unused', function (string $actor): void {
    $org = Organization::factory()->create();
    $used = PlatformTemplates::insertGlobal(['name' => 'Used']);
    $unused = PlatformTemplates::insertGlobal(['name' => 'Unused']);
    patPin($org, $used);

    $token = patToken($actor, $org);

    $this->withToken($token)->getJson("/api/admin/avatar-templates/{$used->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Used')
        ->assertJsonPath('data.scope', 'platform')
        ->assertJsonPath('data.usage', ['organization_count' => 1, 'project_count' => 1]);

    $this->withToken($token)->getJson("/api/admin/avatar-templates/{$unused->id}")
        ->assertOk()
        ->assertJsonPath('data.usage', ['organization_count' => 0, 'project_count' => 0]);
})->with('patSuperadmins');

test('an organization template id is a 404 on the platform show', function (string $actor): void {
    $org = Organization::factory()->create();
    $own = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Own', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));

    $this->withToken(patToken($actor, $org))->getJson("/api/admin/avatar-templates/{$own->id}")->assertNotFound();
})->with('patSuperadmins');

test('every non-superadmin principal is refused the list and the detail with a 403', function (string $actor): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();
    $token = patToken($actor, $org);

    $this->withToken($token)->getJson('/api/admin/avatar-templates')->assertForbidden();
    $this->withToken($token)->getJson("/api/admin/avatar-templates/{$global->id}")->assertForbidden();
})->with('patDenied');

test('an unauthenticated caller gets a 401', function (): void {
    $global = PlatformTemplates::insertGlobal();

    $this->getJson('/api/admin/avatar-templates')->assertUnauthorized();
    $this->getJson("/api/admin/avatar-templates/{$global->id}")->assertUnauthorized();
});

test('/api/auth/me publishes avatarTemplates.manageGlobal to a superadmin only', function (): void {
    $org = Organization::factory()->create();

    $this->withToken(patToken('bare', $org))->getJson('/api/auth/me')->assertJsonPath('abilities.avatarTemplates.manageGlobal', true);

    foreach (['admin', 'operator', 'viewer'] as $role) {
        $this->withToken(patToken($role, $org))->getJson('/api/auth/me')->assertJsonPath('abilities.avatarTemplates.manageGlobal', false);
    }
});
