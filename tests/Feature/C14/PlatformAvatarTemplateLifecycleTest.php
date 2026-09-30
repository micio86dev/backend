<?php

declare(strict_types=1);

/**
 * The platform template LIFECYCLE (global-avatar-templates A4): offer, retire,
 * delete and copy into organizations, all under `api/admin/avatar-templates`.
 *
 * `is_active` on a platform template means "OFFERED for new project pins", not
 * "the one in use": several may be offered at once, retiring never touches an
 * existing pin, and only a retired, unpinned template can be deleted. Every
 * mutation writes ONE `PlatformAuditWriter` row in the same transaction.
 * Read/write coverage (list, show, create, update) is in
 * PlatformAvatarTemplateReadWriteTest.
 */

use App\Exceptions\AvatarTemplateInUseException;
use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\AvatarTemplates\PlatformTemplateContext;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

dataset('pllSuperadmins', ['acting', 'bare']);
dataset('pllDenied', ['admin', 'operator', 'viewer', 'cross_tenant']);

function pllToken(string $actor, Organization $org): string
{
    return $actor === 'cross_tenant'
        ? TemplateActors::token('admin', Organization::factory()->create())
        : TemplateActors::token($actor, $org);
}

function pllPin(Organization $org, AvatarTemplate $template, int $count = 1, bool $trashed = false): void
{
    TenantContextScope::runFor($org->id, function () use ($org, $template, $count, $trashed): void {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

        $projects = Project::factory()->count($count)->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
            'avatar_template_id' => $template->id,
        ]);

        if ($trashed) {
            $projects->each->delete();
        }
    });
}

/** @return list<object> */
function pllAudit(string $action): array
{
    return DB::table('audit_logs')->where('action', $action)->orderBy('id')->get()->all();
}

function pllActive(int $id): bool
{
    return (bool) AvatarTemplate::withoutGlobalScopes()->findOrFail($id)->is_active;
}

// ─── offer (activate) and retire (deactivate) ────────────────────────────────

test('offering two platform templates leaves both offered and both in the organization picker', function (): void {
    $org = Organization::factory()->create();
    $first = PlatformTemplates::insertGlobal(['name' => 'First']);
    $second = PlatformTemplates::insertGlobal(['name' => 'Second']);
    $token = pllToken('bare', $org);

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$first->id}/activate")
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.scope', 'platform');
    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$second->id}/activate")->assertOk();

    expect(pllActive($first->id))->toBeTrue()->and(pllActive($second->id))->toBeTrue();

    $options = $this->withToken(pllToken('admin', $org))->getJson('/api/avatar-templates/options')->assertOk()->json('data');

    expect(collect($options)->where('scope', 'platform')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

test('offering touches no other row, own or platform', function (string $actor): void {
    $org = Organization::factory()->create();
    $own = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Own active', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'], 'is_active' => true,
    ]));
    $otherOffered = PlatformTemplates::insertActiveGlobal(['name' => 'Already offered']);
    $target = PlatformTemplates::insertGlobal(['name' => 'Target']);
    $before = AvatarTemplate::withoutGlobalScopes()->whereKeyNot($target->id)->pluck('updated_at', 'id')->all();

    $this->withToken(pllToken($actor, $org))->postJson("/api/admin/avatar-templates/{$target->id}/activate")->assertOk();

    expect(pllActive($own->id))->toBeTrue()
        ->and(pllActive($otherOffered->id))->toBeTrue()
        ->and(AvatarTemplate::withoutGlobalScopes()->whereKeyNot($target->id)->pluck('updated_at', 'id')->all())->toEqual($before);
})->with('pllSuperadmins');

test('offering re-validates the stored config and refuses a stale one, leaving the template retired', function (): void {
    $stale = PlatformTemplates::insertGlobal(['config' => ['avatarId' => 'only_the_avatar']]);

    $this->withToken(pllToken('bare', Organization::factory()->create()))
        ->postJson("/api/admin/avatar-templates/{$stale->id}/activate")
        ->assertUnprocessable()->assertJsonValidationErrors(['config.voiceId']);

    expect(pllActive($stale->id))->toBeFalse()->and(pllAudit('avatar_template.activated'))->toBe([]);
});

test('retiring a template pinned by three projects in two organizations is allowed and leaves the pins alone', function (string $actor): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();
    pllPin($orgA, $global, 2);
    pllPin($orgB, $global, 1);

    $this->withToken(pllToken($actor, $orgA))->postJson("/api/admin/avatar-templates/{$global->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.usage', ['organization_count' => 2, 'project_count' => 3]);

    expect(pllActive($global->id))->toBeFalse()
        ->and(Project::withoutGlobalScopes()->where('avatar_template_id', $global->id)->count())->toBe(3);
})->with('pllSuperadmins');

test('offering and retiring write one platform audit row each, with no organization', function (): void {
    $global = PlatformTemplates::insertGlobal(['name' => 'Audited']);
    $token = pllToken('acting', Organization::factory()->create());

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$global->id}/activate")->assertOk();
    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$global->id}/deactivate")->assertOk();

    [$activated] = pllAudit('avatar_template.activated');
    [$deactivated] = pllAudit('avatar_template.deactivated');

    expect(pllAudit('avatar_template.activated'))->toHaveCount(1)
        ->and(pllAudit('avatar_template.deactivated'))->toHaveCount(1)
        ->and($activated->organization_id)->toBeNull()
        ->and($activated->subject_type)->toBe('avatar_template')
        ->and($activated->subject_id)->toBe($global->id)
        ->and($activated->actor_id)->not->toBeNull()
        ->and(json_decode($activated->after, true))->toEqual(['name' => 'Audited', 'provider' => 'heygen', 'scope' => 'platform', 'usage' => ['organization_count' => 0, 'project_count' => 0]])
        ->and($deactivated->organization_id)->toBeNull()
        ->and(json_decode($deactivated->before, true))->toEqual(['name' => 'Audited', 'provider' => 'heygen', 'scope' => 'platform', 'usage' => ['organization_count' => 0, 'project_count' => 0]]);
});

test('offering an offered template and retiring a retired one are idempotent and write no audit row', function (): void {
    $offered = PlatformTemplates::insertActiveGlobal(['name' => 'Offered']);
    $retired = PlatformTemplates::insertGlobal(['name' => 'Retired']);
    $token = pllToken('bare', Organization::factory()->create());

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$offered->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$retired->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

    expect(DB::table('audit_logs')->count())->toBe(0);
});

test('a failing audit write rolls the offer back and fails the request loudly', function (): void {
    $global = PlatformTemplates::insertGlobal();

    DB::unprepared('CREATE FUNCTION pll_refuse_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'audit refused\'; END $$ LANGUAGE plpgsql');
    DB::unprepared('CREATE TRIGGER pll_refuse_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION pll_refuse_audit()');

    $this->withToken(pllToken('bare', Organization::factory()->create()))
        ->postJson("/api/admin/avatar-templates/{$global->id}/activate")->assertStatus(500);

    expect(pllActive($global->id))->toBeFalse();
});

test('an organization template id is a 404 on offer and retire', function (): void {
    $org = Organization::factory()->create();
    $own = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Own', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'], 'is_active' => true,
    ]));
    $token = pllToken('bare', $org);

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$own->id}/activate")->assertNotFound();
    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$own->id}/deactivate")->assertNotFound();

    expect(pllActive($own->id))->toBeTrue();
});

test('every non-superadmin principal is refused offer and retire, and nothing changes', function (string $actor): void {
    $org = Organization::factory()->create();
    $retired = PlatformTemplates::insertGlobal(['name' => 'Retired']);
    $offered = PlatformTemplates::insertActiveGlobal(['name' => 'Offered']);
    $token = pllToken($actor, $org);

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$retired->id}/activate")->assertForbidden();
    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$offered->id}/deactivate")->assertForbidden();

    expect(pllActive($retired->id))->toBeFalse()->and(pllActive($offered->id))->toBeTrue()->and(DB::table('audit_logs')->count())->toBe(0);
})->with('pllDenied');

test('an unauthenticated caller cannot offer or retire', function (): void {
    $global = PlatformTemplates::insertGlobal();

    $this->postJson("/api/admin/avatar-templates/{$global->id}/activate")->assertUnauthorized();
    $this->postJson("/api/admin/avatar-templates/{$global->id}/deactivate")->assertUnauthorized();
});

// ─── delete ──────────────────────────────────────────────────────────────────

test('an offered template cannot be deleted: retire it first', function (): void {
    $global = PlatformTemplates::insertActiveGlobal();

    $this->withToken(pllToken('bare', Organization::factory()->create()))
        ->deleteJson("/api/admin/avatar-templates/{$global->id}")
        ->assertStatus(409)
        ->assertExactJson(['error' => 'template_active', 'message' => 'template_active']);

    expect(AvatarTemplate::platformOnly()->find($global->id))->not->toBeNull()->and(pllAudit('avatar_template.deleted'))->toBe([]);
});

test('a retired template pinned across organizations is refused with organization and project counts', function (string $actor): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();
    pllPin($orgA, $global, 2);
    pllPin($orgB, $global, 1);

    $this->withToken(pllToken($actor, $orgA))->deleteJson("/api/admin/avatar-templates/{$global->id}")
        ->assertStatus(409)
        ->assertExactJson(['error' => 'template_in_use', 'message' => 'template_in_use', 'organization_count' => 2, 'project_count' => 3]);

    expect(AvatarTemplate::platformOnly()->find($global->id))->not->toBeNull()->and(pllAudit('avatar_template.deleted'))->toBe([]);
})->with('pllSuperadmins');

test('deleting an unpinned retired template answers 204, audits it once and tolerates a failing HeyGen cleanup', function (): void {
    config()->set('interview.heygen.api_key', 'test-key');
    Http::fake(['*' => Http::response(['message' => 'down'], 500)]);
    $global = PlatformTemplates::insertGlobal(['name' => 'Doomed', 'heygen_llm_configuration_id' => 'cfg_platform_1']);

    $this->withToken(pllToken('acting', Organization::factory()->create()))
        ->deleteJson("/api/admin/avatar-templates/{$global->id}")->assertNoContent();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/llm-configurations/cfg_platform_1'));

    [$row] = pllAudit('avatar_template.deleted');

    expect(AvatarTemplate::platformOnly()->find($global->id))->toBeNull()
        ->and(AvatarTemplate::withoutGlobalScopes()->onlyTrashed()->whereKey($global->id)->exists())->toBeTrue()
        ->and(pllAudit('avatar_template.deleted'))->toHaveCount(1)
        ->and($row->organization_id)->toBeNull()
        ->and($row->subject_id)->toBe($global->id)
        ->and(json_decode($row->before, true))->toEqual(['name' => 'Doomed', 'provider' => 'heygen', 'scope' => 'platform']);
});

test('projects that are already in the trash do not block deleting the template', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();
    pllPin($org, $global, 2, trashed: true);

    $this->withToken(pllToken('bare', $org))->deleteJson("/api/admin/avatar-templates/{$global->id}")->assertNoContent();
});

test('the model refuses a delete that would strand pins and reports both counts', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();
    pllPin($orgA, $global, 2);
    pllPin($orgB, $global, 1);
    $actor = auth('api')->setToken(pllToken('bare', $orgA))->user();

    $thrown = null;

    try {
        app(PlatformTemplateContext::class)->run($actor, fn () => $global->delete());
    } catch (AvatarTemplateInUseException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(AvatarTemplateInUseException::class)
        ->and($thrown->projectCount)->toBe(3)
        ->and($thrown->organizationCount)->toBe(2);
});

test('a pin that appears after the usage check but before the delete is a 409, never a 500', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();
    $armed = true;

    DB::listen(function ($query) use (&$armed, $org, $global): void {
        if ($armed && str_contains($query->sql, 'group by "avatar_template_id"')) {
            $armed = false;
            pllPin($org, $global);
        }
    });

    $this->withToken(pllToken('bare', $org))->deleteJson("/api/admin/avatar-templates/{$global->id}")
        ->assertStatus(409)
        ->assertJson(['error' => 'template_in_use', 'organization_count' => 1, 'project_count' => 1]);

    expect(AvatarTemplate::platformOnly()->find($global->id))->not->toBeNull()->and(pllAudit('avatar_template.deleted'))->toBe([]);
});

test('an organization template id is a 404 on the platform delete', function (): void {
    $org = Organization::factory()->create();
    $own = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Own', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));

    $this->withToken(pllToken('bare', $org))->deleteJson("/api/admin/avatar-templates/{$own->id}")->assertNotFound();

    expect(AvatarTemplate::withoutGlobalScopes()->find($own->id))->not->toBeNull();
});

test('every non-superadmin principal is refused the delete and the template survives', function (string $actor): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();

    $this->withToken(pllToken($actor, $org))->deleteJson("/api/admin/avatar-templates/{$global->id}")->assertForbidden();

    expect(AvatarTemplate::platformOnly()->find($global->id))->not->toBeNull();
})->with('pllDenied');

test('an unauthenticated caller cannot delete', function (): void {
    $global = PlatformTemplates::insertGlobal();

    $this->deleteJson("/api/admin/avatar-templates/{$global->id}")->assertUnauthorized();
});

// ─── duplicate into organizations ────────────────────────────────────────────

/** @return list<AvatarTemplate> */
function pllCopiesIn(Organization $org): array
{
    return TenantContextScope::runFor($org->id, fn () => AvatarTemplate::orderBy('id')->get()->all());
}

test('duplicating a platform template creates an inactive organization copy in every target', function (string $actor): void {
    $source = Organization::factory()->create();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal([
        'name' => 'Corporate voice',
        'description' => 'House style',
        'config' => ['avatarId' => 'av_house', 'voiceId' => 'vo_house'],
        'heygen_llm_configuration_id' => 'cfg_shared',
    ]);

    $response = $this->withToken(pllToken($actor, $source))
        ->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [$orgA->id, $orgB->id]])
        ->assertCreated();

    expect(collect($response->json('data'))->pluck('organization_id')->all())->toBe([$orgA->id, $orgB->id]);

    foreach ([$orgA, $orgB] as $org) {
        [$copy] = pllCopiesIn($org);

        expect(pllCopiesIn($org))->toHaveCount(1)
            ->and($copy->organization_id)->toBe($org->id)
            ->and($copy->name)->toBe('Corporate voice')
            ->and($copy->is_active)->toBeFalse()
            ->and($copy->config)->toEqual(['avatarId' => 'av_house', 'voiceId' => 'vo_house'])
            ->and($copy->heygen_llm_configuration_id)->toBeNull();
    }

    expect(pllActive($global->id))->toBeTrue()->and(pllCopiesIn($source))->toBe([]);
})->with('pllSuperadmins');

test('a copy is independent: editing the platform template afterwards leaves it untouched', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal(['name' => 'Original']);
    $token = pllToken('bare', $org);

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [$org->id]])->assertCreated();
    $this->withToken($token)->patchJson("/api/admin/avatar-templates/{$global->id}", ['name' => 'Edited', 'config' => ['avatarId' => 'av_new', 'voiceId' => 'vo_new']])->assertOk();

    [$copy] = pllCopiesIn($org);

    expect($copy->name)->toBe('Original')->and($copy->config)->toEqual(['avatarId' => 'av_platform', 'voiceId' => 'vo_platform']);
});

test('a name equal to the platform template is not a collision, and one already taken in the target is suffixed', function (): void {
    $free = Organization::factory()->create();
    $taken = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal(['name' => 'Shared name']);
    TenantContextScope::runFor($taken->id, fn () => AvatarTemplate::create([
        'name' => 'Shared name', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));

    $this->withToken(pllToken('bare', $free))
        ->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [$free->id, $taken->id]])
        ->assertCreated();

    expect(collect(pllCopiesIn($free))->pluck('name')->all())->toBe(['Shared name'])
        ->and(collect(pllCopiesIn($taken))->pluck('name')->all())->toBe(['Shared name', 'Shared name (copy)']);
});

test('each copy is audited in its target organization as coming from the platform, without config content', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal(['name' => 'Audited copy', 'config' => ['avatarId' => 'av_secret_9', 'voiceId' => 'vo_secret_9']]);

    $this->withToken(pllToken('bare', $org))
        ->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [$org->id]])->assertCreated();

    [$copy] = pllCopiesIn($org);
    $rows = DB::table('audit_logs')->where('action', 'avatar_template.duplicated')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->organization_id)->toBe($org->id)
        ->and($rows[0]->subject_id)->toBe($copy->id)
        ->and(json_decode($rows[0]->after, true))->toEqual([
            'name' => 'Audited copy',
            'provider' => 'heygen',
            'source_template_id' => $global->id,
            'source_organization_id' => null,
            'source_scope' => 'platform',
        ])
        ->and($rows[0]->after)->not->toContain('av_secret_9');
});

test('the duplicate payload is validated and a stale platform config is refused', function (): void {
    $global = PlatformTemplates::insertGlobal(['config' => ['definitelyNotAKnob' => 'x']]);
    $token = pllToken('bare', Organization::factory()->create());

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", [])
        ->assertUnprocessable()->assertJsonValidationErrors(['target_organization_ids']);
    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [999999]])
        ->assertUnprocessable()->assertJsonValidationErrors(['target_organization_ids.0']);
    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [Organization::factory()->create()->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['template']);
});

test('an organization template id is a 404 on the platform duplicate, and a platform id is a 404 on the organization duplicate', function (): void {
    $org = Organization::factory()->create();
    $target = Organization::factory()->create();
    $own = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Own', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));
    $global = PlatformTemplates::insertGlobal();
    $token = pllToken('bare', $org);
    $payload = ['target_organization_ids' => [$target->id]];

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$own->id}/duplicate", $payload)->assertNotFound();
    $this->withToken($token)->postJson("/api/avatar-templates/{$global->id}/duplicate", $payload)->assertNotFound();

    expect(pllCopiesIn($target))->toBe([]);
});

test('every non-superadmin principal is refused the duplicate and nothing is created', function (string $actor): void {
    $org = Organization::factory()->create();
    $target = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();

    $this->withToken(pllToken($actor, $org))
        ->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [$target->id]])->assertForbidden();

    expect(pllCopiesIn($target))->toBe([])->and(DB::table('audit_logs')->count())->toBe(0);
})->with('pllDenied');

test('an unauthenticated caller cannot duplicate', function (): void {
    $global = PlatformTemplates::insertGlobal();

    $this->postJson("/api/admin/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [1]])->assertUnauthorized();
});
