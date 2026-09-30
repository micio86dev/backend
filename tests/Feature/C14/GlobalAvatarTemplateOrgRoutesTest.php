<?php

declare(strict_types=1);

/**
 * The ORGANIZATION avatar-template routes never see a platform row (A1).
 *
 * A platform id is a plain 404 on every `{id}` route for every principal,
 * including a bare superadmin (bypass on) and a superadmin acting as an org —
 * and `index` never lists one. The strict tenant scope alone would not hold
 * this: under bypass it filters nothing.
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\ActingOrganization;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

function gatToken(string $actor, Organization $org): string
{
    if ($actor === 'admin') {
        $user = User::factory()->create(['organization_id' => $org->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->assignRole(SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]));

        return auth('api')->login($user);
    }

    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    if ($actor === 'acting') {
        app(ActingOrganization::class)->set((int) $user->id, $org->id);
    }

    return auth('api')->login($user);
}

dataset('gatActors', ['admin', 'acting', 'bare']);

test('a platform id is 404 on show, update, activate, deactivate and destroy', function (string $actor): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();
    $token = gatToken($actor, $org);

    $this->withToken($token)->getJson("/api/avatar-templates/{$global->id}")->assertNotFound();
    $this->withToken($token)->patchJson("/api/avatar-templates/{$global->id}", ['name' => 'Hijack'])->assertNotFound();
    $this->withToken($token)->postJson("/api/avatar-templates/{$global->id}/activate")->assertNotFound();
    $this->withToken($token)->postJson("/api/avatar-templates/{$global->id}/deactivate")->assertNotFound();
    $this->withToken($token)->deleteJson("/api/avatar-templates/{$global->id}")->assertNotFound();

    $row = \App\Models\AvatarTemplate::withoutGlobalScopes()->find($global->id);
    expect($row->name)->not->toBe('Hijack')->and($row->is_active)->toBeFalse()->and($row->deleted_at)->toBeNull();
})->with('gatActors');

test('a platform id cannot be duplicated through the organization route', function (): void {
    $org = Organization::factory()->create();
    $target = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();

    foreach (['acting', 'bare'] as $actor) {
        $this->withToken(gatToken($actor, $org))
            ->postJson("/api/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [$target->id]])
            ->assertNotFound();
    }

    // An org admin is refused before the lookup, so the id is never even resolved.
    $this->withToken(gatToken('admin', $org))
        ->postJson("/api/avatar-templates/{$global->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertForbidden();
});

test('index never lists a platform row', function (string $actor): void {
    $org = Organization::factory()->create();
    PlatformTemplates::insertGlobal(['name' => 'Hidden global']);

    $names = $this->withToken(gatToken($actor, $org))
        ->getJson('/api/avatar-templates')
        ->assertOk()
        ->json('data.*.name');

    expect($names)->not->toContain('Hidden global');
})->with('gatActors');
