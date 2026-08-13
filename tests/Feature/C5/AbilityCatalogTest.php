<?php

declare(strict_types=1);

/**
 * Ability catalogue feature tests (C5 — M2M API Authentication).
 *
 * The allowed ability names are a CLOSED set that only the server knows:
 * `AbilitiesValidator` rejects anything outside `config/m2m_abilities.php` with
 * a 422. Before this endpoint the backoffice mirrored that list in a frontend
 * constant, which is drift waiting to happen — an ability added server-side
 * would have been unreachable from the UI, and one removed would have kept
 * being offered until somebody tried it.
 *
 * Asserts:
 * - admin GET → 200, exactly the configured set, in configured order
 * - the response is derived from config, never from a second hardcoded list
 * - operator/viewer → 403 (same policy as the rest of credential management)
 * - unauthenticated → 401
 *
 * REQ-6, REQ-8
 */

use App\Models\Organization;
use App\Models\User;
use App\Services\AbilitiesValidator;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function abilityCatalogUser(Organization $org, string $role): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

test('admin GET /api/m2m/abilities → 200 with the canonical set', function (): void {
    $org = Organization::factory()->create();
    $token = abilityCatalogUser($org, 'admin');

    $response = $this->withToken($token)->getJson('/api/m2m/abilities');

    $response->assertOk()->assertJsonStructure(['data']);

    expect($response->json('data'))->toBe(config('m2m_abilities.allowed'));
});

test('the catalogue follows config, not a second hardcoded list', function (): void {
    $org = Organization::factory()->create();
    $token = abilityCatalogUser($org, 'admin');

    // If the controller ever grew its own copy of the names, this passes
    // upstream and fails here — which is the entire point of the endpoint.
    config()->set('m2m_abilities.allowed', ['participants:read', 'invented:ability']);

    $response = $this->withToken($token)->getJson('/api/m2m/abilities');

    $response->assertOk();
    expect($response->json('data'))->toBe(['participants:read', 'invented:ability']);
});

test('every advertised ability is one the validator actually accepts', function (): void {
    $org = Organization::factory()->create();
    $token = abilityCatalogUser($org, 'admin');

    $advertised = $this->withToken($token)->getJson('/api/m2m/abilities')->json('data');

    // Offering a name the create endpoint would refuse is worse than offering
    // nothing: the operator fills the form and gets an opaque 422.
    expect(AbilitiesValidator::validate($advertised))->toBeTrue();
});

test('operator and viewer are refused, same as the rest of credential management', function (string $role): void {
    $org = Organization::factory()->create();
    $token = abilityCatalogUser($org, $role);

    $this->withToken($token)->getJson('/api/m2m/abilities')->assertForbidden();
})->with(['operator', 'viewer']);

test('unauthenticated GET /api/m2m/abilities → 401', function (): void {
    $this->getJson('/api/m2m/abilities')->assertUnauthorized();
});
