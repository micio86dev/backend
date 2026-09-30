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
use Illuminate\Support\Facades\Http;
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

// ─── create ──────────────────────────────────────────────────────────────────

/** @return array<string, mixed> */
function patPayload(string $name = 'Platform voice', array $config = ['avatarId' => 'av_new', 'voiceId' => 'vo_new']): array
{
    return ['name' => $name, 'provider' => 'heygen', 'config' => $config];
}

function patStoredOrganization(int $id): ?int
{
    return AvatarTemplate::withoutGlobalScopes()->findOrFail($id)->organization_id;
}

test('create persists an inactive platform template with no organization, bare or acting', function (string $actor): void {
    $org = Organization::factory()->create();

    $response = $this->withToken(patToken($actor, $org))->postJson('/api/admin/avatar-templates', patPayload())
        ->assertCreated()
        ->assertJsonPath('data.scope', 'platform')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.usage', ['organization_count' => 0, 'project_count' => 0]);

    // Acting as an organization does NOT stamp it: the platform context is explicit.
    expect(patStoredOrganization($response->json('data.id')))->toBeNull();
})->with('patSuperadmins');

test('create refuses every problem in the config at once, one key per knob', function (): void {
    $response = $this->withToken(patToken('bare', Organization::factory()->create()))
        ->postJson('/api/admin/avatar-templates', patPayload('Bad', ['voiceSpeed' => 99, 'nonsense' => 1]))
        ->assertUnprocessable();

    $response->assertJsonValidationErrors(['config.avatarId', 'config.voiceId', 'config.voiceSpeed', 'config.nonsense']);
    expect(AvatarTemplate::platformOnly()->count())->toBe(0);
});

test('a platform name is unique among live platform templates only', function (): void {
    $org = Organization::factory()->create();
    PlatformTemplates::insertGlobal(['name' => 'Taken']);
    $gone = PlatformTemplates::insertGlobal(['name' => 'Reusable', 'deleted_at' => now()]);
    TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create(patPayload('Shared with an organization')));
    $token = patToken('bare', $org);

    $this->withToken($token)->postJson('/api/admin/avatar-templates', patPayload('Taken'))
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->withToken($token)->postJson('/api/admin/avatar-templates', patPayload('Shared with an organization'))->assertCreated();
    $this->withToken($token)->postJson('/api/admin/avatar-templates', patPayload($gone->name))->assertCreated();

    expect(AvatarTemplate::platformOnly()->where('name', 'Taken')->count())->toBe(1);
});

test('the organization side ignores platform names, so an organization may reuse one', function (): void {
    $org = Organization::factory()->create();
    PlatformTemplates::insertGlobal(['name' => 'Same name']);

    $response = $this->withToken(patToken('acting', $org))->postJson('/api/avatar-templates', patPayload('Same name'))->assertCreated();

    expect(patStoredOrganization($response->json('data.id')))->toBe($org->id);
});

// ─── update ──────────────────────────────────────────────────────────────────

test('update edits a platform template and answers with its usage', function (string $actor): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal(['name' => 'Before']);
    patPin($org, $global, 2);

    $this->withToken(patToken($actor, $org))
        ->patchJson("/api/admin/avatar-templates/{$global->id}", ['name' => 'After', 'config' => ['avatarId' => 'av_2', 'voiceId' => 'vo_2']])
        ->assertOk()
        ->assertJsonPath('data.name', 'After')
        ->assertJsonPath('data.config.voiceId', 'vo_2')
        ->assertJsonPath('data.usage', ['organization_count' => 1, 'project_count' => 2]);

    $row = AvatarTemplate::platformOnly()->findOrFail($global->id);
    expect($row->name)->toBe('After')->and($row->organization_id)->toBeNull();
})->with('patSuperadmins');

test('update refuses a provider change and keeps the provider', function (): void {
    $global = PlatformTemplates::insertGlobal();

    $this->withToken(patToken('bare', Organization::factory()->create()))
        ->patchJson("/api/admin/avatar-templates/{$global->id}", ['provider' => 'tavus'])
        ->assertUnprocessable()->assertJsonValidationErrors(['provider']);

    expect(AvatarTemplate::platformOnly()->findOrFail($global->id)->provider)->toBe('heygen');
});

test('update checks name uniqueness among platform templates excluding the template itself', function (): void {
    $first = PlatformTemplates::insertGlobal(['name' => 'First']);
    PlatformTemplates::insertGlobal(['name' => 'Second']);
    $token = patToken('bare', Organization::factory()->create());

    $this->withToken($token)->patchJson("/api/admin/avatar-templates/{$first->id}", ['name' => 'Second'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->withToken($token)->patchJson("/api/admin/avatar-templates/{$first->id}", ['name' => 'First', 'description' => 'kept'])
        ->assertOk()->assertJsonPath('data.description', 'kept');
});

test('an organization template id is a 404 on the platform update', function (): void {
    $org = Organization::factory()->create();
    $own = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create(patPayload('Own')));

    $this->withToken(patToken('bare', $org))->patchJson("/api/admin/avatar-templates/{$own->id}", ['name' => 'Hijack'])->assertNotFound();

    expect(AvatarTemplate::withoutGlobalScopes()->find($own->id)->name)->toBe('Own');
});

// ─── provider sync ───────────────────────────────────────────────────────────

test('a failed Tavus persona sync is reported on the save and never leaks the provider text', function (): void {
    config()->set('interview.tavus.api_key', 'test-key');
    Http::fake(['*' => Http::response(['message' => 'Tavus persona 404 at tavusapi.com'], 404)]);

    $response = $this->withToken(patToken('bare', Organization::factory()->create()))
        ->postJson('/api/admin/avatar-templates', [
            'name' => 'Tavus platform', 'provider' => 'tavus',
            'config' => ['faceId' => 'f_1', 'palId' => 'p_1', 'llmTemperature' => 0.5],
        ])
        ->assertCreated()
        ->assertJsonPath('warning', 'pal_not_found')
        ->assertJsonPath('data.pal_sync.status', 'warning')
        ->assertJsonPath('data.pal_sync.code', 'pal_not_found');

    expect($response->getContent())->not->toContain('tavusapi');
    expect(AvatarTemplate::platformOnly()->findOrFail($response->json('data.id'))->pal_sync_status)->toBe('warning');
});

// ─── refusal ─────────────────────────────────────────────────────────────────

test('every non-superadmin principal is refused create and update, and nothing changes', function (string $actor): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal(['name' => 'Untouched']);
    $token = patToken($actor, $org);

    $this->withToken($token)->postJson('/api/admin/avatar-templates', patPayload())->assertForbidden();
    $this->withToken($token)->patchJson("/api/admin/avatar-templates/{$global->id}", ['name' => 'Hijack'])->assertForbidden();

    expect(AvatarTemplate::platformOnly()->pluck('name')->all())->toBe(['Untouched']);
})->with('patDenied');

test('an unauthenticated caller cannot create or update', function (): void {
    $global = PlatformTemplates::insertGlobal();

    $this->postJson('/api/admin/avatar-templates', patPayload())->assertUnauthorized();
    $this->patchJson("/api/admin/avatar-templates/{$global->id}", ['name' => 'x'])->assertUnauthorized();
});
