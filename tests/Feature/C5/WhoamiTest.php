<?php

declare(strict_types=1);

/**
 * Whoami endpoint tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - valid key → 200 with {client_id, organization_id, abilities}
 * - no ability middleware required (auth alone is sufficient)
 * - expired key → 401
 * - api_key absent from response
 * - key_hash absent from response
 *
 * REQ-9
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;

test('valid key → 200 with client_id, organization_id, abilities', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
        'abilities'       => ['participants:read', 'projects:read'],
        'is_active'       => true,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/m2m/whoami');

    $response->assertOk()
        ->assertJsonStructure(['client_id', 'organization_id', 'abilities'])
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonPath('organization_id', $org->id);

    $abilities = $response->json('abilities');
    expect($abilities)->toContain('participants:read');
    expect($abilities)->toContain('projects:read');
});

test('expired key → 401', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
        'is_active'       => true,
        'expires_at'      => now()->subHour(),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/m2m/whoami')
        ->assertUnauthorized();
});

test('api_key is absent from whoami response', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/m2m/whoami');

    $response->assertOk();
    expect($response->json())->not->toHaveKey('api_key');
});

test('key_hash is absent from whoami response', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/m2m/whoami');

    $response->assertOk();
    expect($response->json())->not->toHaveKey('key_hash');
});

test('missing key → 401 on whoami', function (): void {
    $this->getJson('/api/m2m/whoami')
        ->assertUnauthorized();
});
