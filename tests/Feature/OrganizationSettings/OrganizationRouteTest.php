<?php

declare(strict_types=1);

/**
 * GET/PATCH /api/organization — the singular, self-resolving organization
 * settings route (backoffice-missing-pages D2).
 *
 * No id ever appears in the path: the org resolves exclusively from the
 * authenticated user's organization_id, so there is no IDOR surface to test
 * and no cross-org id that could ever reach the query layer.
 *
 * REQ: Singular Self-Resolving Organization Route, Organization Profile Is
 *      Name-Only, Organization Webhook Defaults Are Copy-On-Create
 *      (openspec/changes/backoffice-missing-pages/specs/organization-settings/spec.md)
 */

use App\Models\Organization;

test('GET /api/organization always resolves the caller org, ignoring any id/query param', function (): void {
    $org = Organization::factory()->create(['name' => 'Acme Corp']);
    $token = authTokenForRole($org, 'admin');

    $response = $this->withToken($token)->getJson('/api/organization?id=999&organization_id=999');

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Acme Corp');
    expect($response->json('data.slug'))->toBe($org->slug);
});

test('no route variant accepts an {organization} path parameter', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');

    // A path-parameterised variant must not exist at all — asserted as 404
    // (route not found), not merely "not authorized".
    $response = $this->withToken($token)->getJson("/api/organizations/{$org->id}");

    $response->assertNotFound();
});

test('admin can PATCH the organization name; slug is never writable', function (): void {
    $org = Organization::factory()->create(['name' => 'Old Name', 'slug' => 'old-slug']);
    $token = authTokenForRole($org, 'admin');

    $response = $this->withToken($token)->patchJson('/api/organization', [
        'name' => 'New Name',
        'slug' => 'hacked-slug',
    ]);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('New Name');
    expect($response->json('data.slug'))->toBe('old-slug');
    expect($org->fresh()->slug)->toBe('old-slug');
});

test('operator and viewer cannot PATCH the organization', function (string $role): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, $role);

    $response = $this->withToken($token)->patchJson('/api/organization', ['name' => 'New Name']);

    $response->assertForbidden();
})->with(['operator', 'viewer']);

test('GET /api/organization never returns the default_webhook_secret key', function (): void {
    $org = Organization::factory()->create(['default_webhook_secret' => 'super-secret-value']);
    $token = authTokenForRole($org, 'admin');

    $response = $this->withToken($token)->getJson('/api/organization');

    $response->assertOk();
    $data = $response->json('data');
    expect(array_key_exists('default_webhook_secret', $data))->toBeFalse();
    expect(json_encode($data))->not->toContain('super-secret-value');
});
