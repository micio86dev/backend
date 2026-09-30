<?php

declare(strict_types=1);

/**
 * `projects.avatar_template_id` accepts an own template OR an ACTIVE global (A2).
 *
 * A platform template is offered to every organization for a NEW pin, so create
 * and update accept an active one; a retired one is refused as a new choice but
 * an UNCHANGED pin to it stays valid, or retiring a global would make every
 * project pinned to it unsaveable. Another organization's template is refused
 * exactly as before, and a bare superadmin (no organization) still matches
 * nothing.
 */

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function gprPayload(Organization $org, int $templateId): array
{
    $fv = TenantContextScope::runFor($org->id, fn (): FrameworkVersion => FrameworkVersion::factory()->create(['organization_id' => $org->id]));

    return [
        'framework_version_id' => $fv->id,
        'slug' => 'pin-rule-'.uniqid(),
        'name' => 'Pin rule',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'avatar_template_id' => $templateId,
    ];
}

function gprOwnTemplate(Organization $org, bool $active = false): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Own '.uniqid(), 'provider' => 'heygen', 'config' => [], 'is_active' => $active,
    ]));
}

function gprProjectPinnedTo(Organization $org, AvatarTemplate $template): Project
{
    return TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create(['avatar_template_id' => $template->id]));
}

dataset('gprActors', ['admin', 'operator', 'acting']);

test('create accepts an active global and an own inactive template', function (string $actor): void {
    $org = Organization::factory()->create();
    $token = TemplateActors::token($actor, $org);

    $global = PlatformTemplates::insertActiveGlobal();
    $this->withToken($token)->postJson('/api/projects', gprPayload($org, $global->id))
        ->assertCreated()->assertJsonPath('data.avatar_template_id', $global->id);

    $own = gprOwnTemplate($org);
    $this->withToken($token)->postJson('/api/projects', gprPayload($org, $own->id))
        ->assertCreated()->assertJsonPath('data.avatar_template_id', $own->id);
})->with('gprActors');

test('create refuses a retired global, another organization template and an unknown or trashed id', function (): void {
    $org = Organization::factory()->create();
    $token = TemplateActors::token('operator', $org);
    $foreign = gprOwnTemplate(Organization::factory()->create(), true);
    $trashed = gprOwnTemplate($org);
    TenantContextScope::runFor($org->id, fn () => $trashed->delete());

    foreach ([PlatformTemplates::insertGlobal()->id, $foreign->id, $trashed->id, 999999] as $id) {
        $this->withToken($token)->postJson('/api/projects', gprPayload($org, $id))
            ->assertStatus(422)->assertJsonValidationErrors('avatar_template_id');
    }
});

test('an unchanged pin to a since-retired global stays valid on update', function (string $actor): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();
    $project = gprProjectPinnedTo($org, $global);
    $token = TemplateActors::token($actor, $org);

    DB::table('avatar_templates')->where('id', $global->id)->update(['is_active' => false]);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['name' => 'Renamed', 'avatar_template_id' => $global->id])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.avatar_template_id', $global->id);
})->with('gprActors');

test('update refuses a switch TO a retired global, but accepts a switch to an active one', function (): void {
    $org = Organization::factory()->create();
    $current = PlatformTemplates::insertActiveGlobal();
    $retired = PlatformTemplates::insertGlobal();
    $active = PlatformTemplates::insertActiveGlobal();
    $project = gprProjectPinnedTo($org, $current);
    $token = TemplateActors::token('admin', $org);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['avatar_template_id' => $retired->id])
        ->assertStatus(422)->assertJsonValidationErrors('avatar_template_id');

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['avatar_template_id' => $active->id])
        ->assertOk()->assertJsonPath('data.avatar_template_id', $active->id);
});

test('update keeps the pin when the field is omitted and refuses an explicit null', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();
    $project = gprProjectPinnedTo($org, $global);
    $token = TemplateActors::token('admin', $org);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['name' => 'Only a name'])
        ->assertOk()->assertJsonPath('data.avatar_template_id', $global->id);

    $this->withToken($token)->patchJson("/api/projects/{$project->id}", ['avatar_template_id' => null])
        ->assertStatus(422)->assertJsonValidationErrors('avatar_template_id');
});

test('a bare superadmin still cannot create a project: there is no organization to pin for', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();

    $this->withToken(TemplateActors::token('bare', $org))->postJson('/api/projects', gprPayload($org, $global->id))
        ->assertStatus(422)->assertJsonValidationErrors('avatar_template_id');
});

test('a brand-new organization with no template of its own creates its first project on a global', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();

    $id = $this->withToken(TemplateActors::token('admin', $org))->postJson('/api/projects', gprPayload($org, $global->id))
        ->assertCreated()->json('data.id');

    $resolved = TenantContextScope::runFor($org->id, fn () => app(ActiveTemplateResolver::class)->resolve('heygen', $id));
    expect($resolved?->id)->toBe($global->id);
});
