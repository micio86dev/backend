<?php

declare(strict_types=1);

/**
 * ApiClient index feature tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - admin GET → 200 list
 * - only own-org clients visible
 * - operator/viewer → 403
 * - key_hash/api_key absent from list response
 *
 * REQ-8, REQ-10
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function indexUser(Organization $org, string $role): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

test('admin GET /api/m2m/clients → 200 list of own-org clients', function (): void {
    $org = Organization::factory()->create();
    $token = indexUser($org, 'admin');

    ApiClient::factory()->count(3)->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->getJson('/api/m2m/clients');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'abilities', 'is_active']]]);

    expect(count($response->json('data')))->toBe(3);
});

test('only own-org clients visible (cross-org isolation)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $token = indexUser($orgA, 'admin');

    ApiClient::factory()->count(2)->create(['organization_id' => $orgA->id]);
    ApiClient::factory()->count(3)->create(['organization_id' => $orgB->id]);

    $response = $this->withToken($token)->getJson('/api/m2m/clients');

    $response->assertOk();
    // Must see only 2 (org A), not 5 (all)
    expect(count($response->json('data')))->toBe(2);
});

test('key_hash absent from index response', function (): void {
    $org = Organization::factory()->create();
    $token = indexUser($org, 'admin');

    ApiClient::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->getJson('/api/m2m/clients');

    $response->assertOk();

    foreach ($response->json('data') as $item) {
        expect($item)->not->toHaveKey('key_hash');
        expect($item)->not->toHaveKey('api_key');
    }
});

test('operator GET → 403', function (): void {
    $org = Organization::factory()->create();
    $token = indexUser($org, 'operator');

    $this->withToken($token)->getJson('/api/m2m/clients')
        ->assertForbidden();
});

test('viewer GET → 403', function (): void {
    $org = Organization::factory()->create();
    $token = indexUser($org, 'viewer');

    $this->withToken($token)->getJson('/api/m2m/clients')
        ->assertForbidden();
});
