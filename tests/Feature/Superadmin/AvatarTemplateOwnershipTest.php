<?php

declare(strict_types=1);

/**
 * Avatar templates are PLATFORM data, not client data (2026-09-02).
 *
 * A client selects a template for a project and does not author one:
 * "i templates non li crea né modifica né elimina". Managing them moved to the
 * superadmin, who is the only role that exists above the tenants.
 *
 * Reading did NOT move. An org admin keeps `viewAny`/`view`, so the templates
 * page stays consultable — read-only is the shape of "you select from these",
 * and removing the page would answer a question nobody asked.
 *
 * `listOptions` is untouched and stays open to every role: it returns id, name
 * and provider only, which is exactly what choosing one requires, and every
 * project must name a template because the column is NOT NULL.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function atoAdmin(Organization $org): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return $user;
}

function atoTemplate(Organization $org): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Ada',
        'provider' => 'heygen',
        'config' => [],
        'is_active' => false,
    ]));
}

test('an org admin can no longer create a template', function (): void {
    $org = Organization::factory()->create();
    $admin = atoAdmin($org);

    expect($admin->can('create', AvatarTemplate::class))->toBeFalse();
});

test('an org admin can no longer update or delete one', function (): void {
    $org = Organization::factory()->create();
    $admin = atoAdmin($org);
    $template = atoTemplate($org);

    expect($admin->can('update', $template))->toBeFalse()
        ->and($admin->can('delete', $template))->toBeFalse();
});

test('an org admin can still READ them, so the page stays consultable', function (): void {
    $org = Organization::factory()->create();
    $admin = atoAdmin($org);
    $template = atoTemplate($org);

    expect($admin->can('viewAny', AvatarTemplate::class))->toBeTrue()
        ->and($admin->can('view', $template))->toBeTrue();
});

test('every role can still see the PICKER, because a project must name one', function (): void {
    $org = Organization::factory()->create();
    $admin = atoAdmin($org);

    expect($admin->can('listOptions', AvatarTemplate::class))->toBeTrue();
});

test('a superadmin manages them', function (): void {
    // Through Gate::before, which grants a superadmin every ability — so the
    // policy denying the org roles is enough, and no superadmin branch is
    // repeated in each method.
    $org = Organization::factory()->create();
    $template = atoTemplate($org);
    $root = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    expect($root->can('create', AvatarTemplate::class))->toBeTrue()
        ->and($root->can('update', $template))->toBeTrue()
        ->and($root->can('delete', $template))->toBeTrue();
});
