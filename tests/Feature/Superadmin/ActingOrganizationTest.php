<?php

declare(strict_types=1);

/**
 * The superadmin's acting organization (RATIFIED 2026-09-02, option b).
 *
 * The DATA layer for superadmins already existed: `users.is_superadmin`, a
 * null `organization_id`, `TenantContext` setting `setBypass(true)` and
 * `TenantScoped` skipping its filter. What did not exist was any way to
 * NARROW that view to one client, or for the backoffice to know it was
 * talking to a superadmin at all.
 *
 * The switch is SERVER-SIDE, never a header the client sends. The rejected
 * alternative — `X-Organization-Id` on every request — puts a cross-tenant
 * lever in a place any caller can set, so every endpoint would have to honour
 * it correctly and one mistake becomes a cross-tenant leak. CLAUDE.md's
 * binding constraint is "a tenant must never see another tenant's data", and a
 * header that overrides it is precisely the surface that constraint exists to
 * avoid.
 */

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// saSuperadmin() / saOrgAdmin() / saProject() moved to tests/Pest.php.
// They were declared HERE and used from ClientOverviewTest.php too, which made
// that file impossible to run on its own: `pest <thatfile>` never loads this
// one, so every test in it died on "Call to undefined function saProject()".
// The whole suite stayed green because both files load together — a test file
// that only passes with a neighbour present is not a test file you can trust
// in isolation, and it silently turned a mutation check into a false positive.

test('the profile tells the backoffice it is talking to a superadmin', function (): void {
    // Without this the UI cannot branch at all: `is_superadmin` was exposed in
    // no resource and in no ability map, so the client had no way to know.
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.is_superadmin', true);

    // And on /auth/me, which is the contract the SHELL reads: useCurrentUser
    // caches it once per page load, so the switcher has to exist before the
    // first navigation rather than after a second request.
    $this->withToken($token)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.is_superadmin', true);
});

test('an ordinary admin is not one, and is told so explicitly', function (): void {
    // Explicit `false` rather than an absent key: a UI that branches on
    // `undefined` treats "not sent" and "not a superadmin" the same, and would
    // start showing the switcher the day the field is renamed.
    $org = Organization::factory()->create();
    ['token' => $token] = saOrgAdmin($org);

    $this->withToken($token)->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.is_superadmin', false);
});

test('a superadmin sees every client', function (): void {
    Organization::factory()->count(3)->create();
    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->getJson('/api/admin/organizations');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

test('an ordinary admin cannot list clients', function (): void {
    // 403, not 404: the ROUTE is not a secret and the caller is authenticated —
    // what is refused is the capability. The cross-tenant 404 doctrine applies
    // to individual records, not to an endpoint that exists for one role.
    $org = Organization::factory()->create();
    ['token' => $token] = saOrgAdmin($org);

    $this->withToken($token)->getJson('/api/admin/organizations')->assertForbidden();
});

test('acting as one client narrows every read to it', function (): void {
    $acme = Organization::factory()->create(['name' => 'Acme']);
    $globex = Organization::factory()->create(['name' => 'Globex']);
    saProject($acme, 'acme-one');
    saProject($globex, 'globex-one');

    ['token' => $token] = saSuperadmin();

    // Unscoped first: a superadmin sees both.
    expect($this->withToken($token)->getJson('/api/projects')->json('data'))->toHaveCount(2);

    $this->withToken($token)
        ->putJson('/api/admin/acting-organization', ['organization_id' => $acme->id])
        ->assertOk();

    $scoped = $this->withToken($token)->getJson('/api/projects');

    expect($scoped->json('data'))->toHaveCount(1)
        ->and($scoped->json('data.0.slug'))->toBe('acme-one');
});

test('clearing it restores the all-clients view', function (): void {
    $acme = Organization::factory()->create();
    $globex = Organization::factory()->create();
    saProject($acme, 'acme-two');
    saProject($globex, 'globex-two');

    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->putJson('/api/admin/acting-organization', ['organization_id' => $acme->id])->assertOk();
    $this->withToken($token)->putJson('/api/admin/acting-organization', ['organization_id' => null])->assertOk();

    expect($this->withToken($token)->getJson('/api/projects')->json('data'))->toHaveCount(2);
});

test('an ordinary admin cannot set an acting organization', function (): void {
    // THE test of this design. If this ever passes with a 200, an org admin
    // has just acquired a lever that reads another tenant's data — the exact
    // failure the header-based alternative was rejected for.
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    ['token' => $token] = saOrgAdmin($mine);

    $this->withToken($token)
        ->putJson('/api/admin/acting-organization', ['organization_id' => $theirs->id])
        ->assertForbidden();
});
