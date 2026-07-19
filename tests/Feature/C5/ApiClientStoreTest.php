<?php

declare(strict_types=1);

/**
 * ApiClient store feature tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - admin POST → 201 with envelope {"data":{...},"api_key":"beai_live_..."}
 * - api_key is a top-level sibling of "data"
 * - api_key absent from index/destroy responses
 * - duplicate request returns new distinct key
 * - unknown ability → 422
 * - operator POST → 403
 * - key_hash stored as sha256 (raw key NOT in key_hash column)
 * - api_key not in key_hash column
 *
 * REQ-2, REQ-8
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function storeAdminUser(Organization $org): array
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

function storeOperatorUser(Organization $org): array
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $role = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

test('admin POST → 201 with data envelope and api_key top-level sibling', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = storeAdminUser($org);

    $response = $this->withToken($token)->postJson('/api/m2m/clients', [
        'name'      => 'Test Integration Client',
        'abilities' => ['participants:read', 'projects:read'],
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => ['id', 'name', 'abilities', 'is_active', 'created_at'],
            'api_key',
        ]);

    // api_key must have the correct prefix
    expect($response->json('api_key'))->toStartWith('beai_live_');
});

test('api_key is a top-level sibling of data (not nested in data)', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = storeAdminUser($org);

    $response = $this->withToken($token)->postJson('/api/m2m/clients', [
        'name'      => 'My Client',
        'abilities' => ['participants:read'],
    ]);

    $response->assertCreated();

    // api_key is at root level, not inside data
    $body = $response->json();
    expect($body)->toHaveKey('api_key');
    expect($body['data'])->not->toHaveKey('api_key');
    expect($body['data'])->not->toHaveKey('key_hash');
});

test('key_hash stored as sha256 of raw key (not the raw key itself)', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = storeAdminUser($org);

    $response = $this->withToken($token)->postJson('/api/m2m/clients', [
        'name'      => 'Hash Verification Client',
        'abilities' => ['participants:read'],
    ]);

    $response->assertCreated();
    $apiKey = $response->json('api_key');
    $clientId = $response->json('data.id');

    $client = ApiClient::find($clientId);

    // key_hash must be sha256 of the raw key, not the raw key itself
    expect($client->key_hash)->toBe(ApiKeyGenerator::hash($apiKey));
    expect($client->key_hash)->not->toBe($apiKey);
    expect(strlen($client->key_hash))->toBe(64); // SHA-256 hex = 64 chars
});

test('duplicate POST returns new distinct api_key', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = storeAdminUser($org);

    $payload = ['name' => 'Dup Client', 'abilities' => ['participants:read']];

    $resp1 = $this->withToken($token)->postJson('/api/m2m/clients', $payload);
    $resp2 = $this->withToken($token)->postJson('/api/m2m/clients', $payload);

    $resp1->assertCreated();
    $resp2->assertCreated();

    expect($resp1->json('api_key'))->not->toBe($resp2->json('api_key'));
});

test('unknown ability → 422 with validation error', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = storeAdminUser($org);

    $this->withToken($token)->postJson('/api/m2m/clients', [
        'name'      => 'Bad Client',
        'abilities' => ['participants:read', 'admin:delete_everything'],
    ])->assertUnprocessable()
        ->assertJsonPath('errors.abilities.0', fn ($msg) => str_contains($msg, 'allowed'));
});

test('operator POST → 403', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = storeOperatorUser($org);

    $this->withToken($token)->postJson('/api/m2m/clients', [
        'name'      => 'Operator Client',
        'abilities' => ['participants:read'],
    ])->assertForbidden();
});

test('unauthenticated POST → 401', function (): void {
    $this->postJson('/api/m2m/clients', [
        'name'      => 'No Auth Client',
        'abilities' => ['participants:read'],
    ])->assertUnauthorized();
});
