<?php

declare(strict_types=1);

/**
 * What `/auth/me` publishes to a superadmin who has NOT selected a client.
 *
 * `Gate::before` grants a superadmin every policy, so the policies cannot say "no organization is selected":
 * the only place that can is `UserAbilities`. With no organization in context the org-scoped groups must be
 * `false` (a Projects or API-keys screen offered there can only end in a 409), while the groups that are
 * platform-scope in that state must stay `true` — `users.viewAny` is the ability that guards `/settings`, which
 * hosts the platform user list, the platform credentials and the platform settings, and `avatarTemplates.*`
 * guards two `scope: 'platform'` pages. Suppressing those would lock the superadmin out of the very screens used
 * to pick a client.
 *
 * The identity is the production one: `organization_id = null`, `is_superadmin = true` (`saSuperadmin()`).
 */

use App\Models\Organization;
use App\Support\Tenancy\ActingOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, bool>  $keys
 * @return array<string, bool>
 */
function allAbilities(array $keys, bool $value): array
{
    return array_map(static fn (bool $_): bool => $value, $keys);
}

test('with no acting organization the org-scoped groups are all false and the platform groups are untouched', function (): void {
    ['token' => $token] = saSuperadmin();

    $abilities = $this->withToken($token)->getJson('/api/auth/me')->assertOk()->json('abilities');

    foreach (['organization', 'apiClients', 'projects', 'participants'] as $group) {
        expect($abilities[$group])->toBe(allAbilities($abilities[$group], false), "{$group} must be fully suppressed");
    }

    foreach (['users', 'llmCredentials', 'avatarTemplates', 'clients', 'platformSettings', 'catalogue'] as $group) {
        expect($abilities[$group])->toBe(allAbilities($abilities[$group], true), "{$group} is platform-scope and must stay true");
    }
});

test('acting as an organization restores every group, with the same shape', function (): void {
    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = saSuperadmin();

    $bare = $this->withToken($token)->getJson('/api/auth/me')->assertOk()->json('abilities');

    app(ActingOrganization::class)->set((int) $user->id, (int) $org->id);
    $acting = $this->withToken($token)->getJson('/api/auth/me')->assertOk()->json('abilities');

    expect(array_keys($acting))->toBe(array_keys($bare));
    foreach ($acting as $group => $keys) {
        expect(array_keys($keys))->toBe(array_keys($bare[$group]), "{$group} keeps its keys");
        expect($keys)->toBe(allAbilities($keys, true), "{$group} is fully granted while acting");
    }
});

test('suppression never touches an organization member, whatever their role', function (string $role): void {
    $org = Organization::factory()->create();

    $abilities = $this->withToken(authTokenForRole($org, $role))->getJson('/api/auth/me')->assertOk()->json('abilities');

    // Every role sees its own organization's `organization.view` and `projects.viewAny`: a suppressed group would
    // have turned both false.
    expect($abilities['organization']['view'])->toBeTrue()
        ->and($abilities['projects']['viewAny'])->toBeTrue()
        ->and($abilities['participants']['viewAny'])->toBeTrue();
})->with(['admin', 'operator', 'viewer']);
