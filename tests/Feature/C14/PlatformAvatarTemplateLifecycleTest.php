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

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
