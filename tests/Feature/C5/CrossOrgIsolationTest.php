<?php

declare(strict_types=1);

/**
 * Cross-org isolation tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - org from client record is always used (never from request input)
 * - a client of org A cannot forge org B context
 *
 * REQ-10 / design §Route isolation
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;

test('whoami reflects client organization_id, not a forged org from request', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $orgA->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
        'abilities'       => ['participants:read'],
    ]);

    // Attempt to pass org B's ID in the request body / header
    $response = $this->withHeaders([
        'Authorization'    => 'Bearer ' . $rawKey,
        'X-Organization-Id' => (string) $orgB->id,
    ])->postJson('/api/m2m/whoami', ['organization_id' => $orgB->id]);

    // whoami is GET; use GET with query params
    $response = $this->withHeaders([
        'Authorization'    => 'Bearer ' . $rawKey,
        'X-Organization-Id' => (string) $orgB->id,
    ])->getJson('/api/m2m/whoami?organization_id=' . $orgB->id);

    // org must always be org A (from the client record), never org B
    $response->assertOk()
        ->assertJsonPath('organization_id', $orgA->id);
});

test('resolver org_id comes from client record, not request input', function (): void {
    $orgA = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $orgA->id,
        'key_hash'        => ApiKeyGenerator::hash($rawKey),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer ' . $rawKey])
        ->getJson('/api/m2m/whoami');

    $resolver = app(TenantResolver::class);
    expect($resolver->getOrgId())->toBe($orgA->id);
});
