<?php

declare(strict_types=1);

/**
 * Non-admin management tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - operator create → 403
 * - viewer create → 403
 * - operator revoke → 403
 * - machine api_key on auth:api mgmt route → 401 (guard non-interchangeability)
 *
 * REQ-8 / design §Guard non-interchangeability
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function nonAdminToken(Organization $org, string $role): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

test('operator create → 403', function (): void {
    $org = Organization::factory()->create();
    $token = nonAdminToken($org, 'operator');

    $this->withToken($token)->postJson('/api/m2m/clients', [
        'name'      => 'Test',
        'abilities' => ['participants:read'],
    ])->assertForbidden();
});

test('viewer create → 403', function (): void {
    $org = Organization::factory()->create();
    $token = nonAdminToken($org, 'viewer');

    $this->withToken($token)->postJson('/api/m2m/clients', [
        'name'      => 'Test',
        'abilities' => ['participants:read'],
    ])->assertForbidden();
});

test('operator revoke → 403', function (): void {
    $org = Organization::factory()->create();
    $token = nonAdminToken($org, 'operator');
    $client = ApiClient::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)
        ->deleteJson('/api/m2m/clients/' . $client->id)
        ->assertForbidden();
});

test('machine api_key on auth:api mgmt route → 401 (guard non-interchangeability)', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
        'is_active'       => true,
    ]);

    // An M2M key must NOT work on the human auth:api credential mgmt route
    $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->postJson('/api/m2m/clients', [
            'name'      => 'Attempted',
            'abilities' => ['participants:read'],
        ])
        ->assertUnauthorized();
});
