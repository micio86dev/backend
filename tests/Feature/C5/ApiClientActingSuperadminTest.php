<?php

declare(strict_types=1);

/**
 * ApiClient endpoints seen by a SUPERADMIN acting as one client.
 *
 * `TenantContext` narrows a superadmin to the selected client on the RESOLVER
 * (`TenantContext::handle()`), and deliberately leaves `users.organization_id`
 * null — that null is what makes them a superadmin in the first place, and
 * overwriting it would turn a view into an impersonation.
 *
 * Every tenant model reads the resolver through `TenantScoped`, so they all
 * follow the selection for free. `ApiClient` is NOT a TenantModel (the M2M
 * guard has to query it before any tenant context exists), so it is the one
 * place that has to ask the resolver itself — and until this test existed it
 * asked `$user->organization_id` instead: the list came back empty on every
 * request and creating a key wrote a null `organization_id` into a NOT NULL
 * column.
 *
 * Also pins the no-client-selected shape. The backoffice hides the section
 * there, but an endpoint may not depend on a rail to stay safe: the list is
 * empty and the create is REFUSED, never a 500 and never a tenant-less key.
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\ActingOrganization;

/**
 * A superadmin, and the client they are acting as (null = all clients).
 *
 * @return array{user: User, token: string}
 */
function actingSuperadmin(?Organization $org): array
{
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    if ($org !== null) {
        app(ActingOrganization::class)->set((int) $user->id, (int) $org->id);
    }

    return ['user' => $user, 'token' => (string) auth('api')->login($user)];
}

test('superadmin acting as a client lists THAT client\'s keys', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    ApiClient::factory()->count(2)->create(['organization_id' => $orgA->id]);
    ApiClient::factory()->count(3)->create(['organization_id' => $orgB->id]);

    ['token' => $token] = actingSuperadmin($orgA);

    $response = $this->withToken($token)->getJson('/api/m2m/clients');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

test('superadmin acting as a client creates the key UNDER that client', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = actingSuperadmin($org);

    $response = $this->withToken($token)->postJson('/api/m2m/clients', [
        'name' => 'Acting key',
        'abilities' => ['participants:create'],
    ]);

    $response->assertCreated();

    $client = ApiClient::query()->findOrFail($response->json('data.id'));
    expect($client->organization_id)->toBe($org->id);
});

test('superadmin acting as a client revokes that client\'s key', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

    ['token' => $token] = actingSuperadmin($org);

    $this->withToken($token)->deleteJson("/api/m2m/clients/{$client->id}")->assertNoContent();

    expect($client->fresh()->is_active)->toBeFalse();
});

test('superadmin with NO client selected sees an empty list, not another tenant\'s keys', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    ApiClient::factory()->count(2)->create(['organization_id' => $orgA->id]);
    ApiClient::factory()->count(3)->create(['organization_id' => $orgB->id]);

    ['token' => $token] = actingSuperadmin(null);

    $response = $this->withToken($token)->getJson('/api/m2m/clients');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('superadmin with NO client selected cannot create a tenant-less key', function (): void {
    ['token' => $token] = actingSuperadmin(null);

    $response = $this->withToken($token)->postJson('/api/m2m/clients', [
        'name' => 'Orphan key',
        'abilities' => ['participants:create'],
    ]);

    $response->assertStatus(409)
        ->assertJson(['error' => 'no_client_selected']);

    expect(ApiClient::query()->count())->toBe(0);
});
