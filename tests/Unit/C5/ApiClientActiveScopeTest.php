<?php

declare(strict_types=1);

/**
 * ApiClient::active() scope unit tests (C5 — M2M API Authentication).
 *
 * S1: Direct unit coverage for the active() scope.
 *
 * Asserts:
 * - An active + non-expired client is returned by the scope.
 * - An inactive client (is_active=false) is excluded.
 * - An expired client (expires_at in the past) is excluded.
 * - A client with null expires_at is treated as non-expiring (always valid).
 * - Control: active client with a future expires_at is included.
 *
 * Uses RefreshDatabase via Feature/C5 bootstrap (this file lives in Unit/C5
 * which extends TestCase but does NOT use RefreshDatabase by default).
 * We need DB access to assert real query results, so we use the
 * `uses(RefreshDatabase::class)` Pest DSL on this file explicitly.
 *
 * REQ-1, REQ-3 / design §ApiClient — active() scope
 */

use App\Models\ApiClient;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// S1a — active + no expires_at (non-expiring) → included
// ---------------------------------------------------------------------------

test('S1 — active + null expires_at → included in active() scope', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'expires_at' => null,
    ]);

    $result = ApiClient::active()->where('id', $client->id)->first();

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($client->id);
});

// ---------------------------------------------------------------------------
// S1b — active + future expires_at → included (control: valid expiring client)
// ---------------------------------------------------------------------------

test('S1 — active + future expires_at → included in active() scope', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'expires_at' => now()->addYear(),
    ]);

    $result = ApiClient::active()->where('id', $client->id)->first();

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($client->id);
});

// ---------------------------------------------------------------------------
// S1c — inactive (is_active=false) → excluded
// ---------------------------------------------------------------------------

test('S1 — inactive client (is_active=false) → excluded from active() scope', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->inactive()->create([
        'organization_id' => $org->id,
        'expires_at' => null,
    ]);

    $result = ApiClient::active()->where('id', $client->id)->first();

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// S1d — expired (expires_at in the past) → excluded
// ---------------------------------------------------------------------------

test('S1 — expired client (expires_at in the past) → excluded from active() scope', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->expired()->create([
        'organization_id' => $org->id,
        'is_active' => true,
    ]);

    $result = ApiClient::active()->where('id', $client->id)->first();

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// S1e — inactive + expired → excluded (belt-and-suspenders)
// ---------------------------------------------------------------------------

test('S1 — inactive + expired client → excluded from active() scope', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->inactive()->expired()->create([
        'organization_id' => $org->id,
    ]);

    $result = ApiClient::active()->where('id', $client->id)->first();

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// S1f — scope filters mixed set correctly (only valid clients returned)
// ---------------------------------------------------------------------------

test('S1 — active() scope returns only valid clients from a mixed set', function (): void {
    $org = Organization::factory()->create();

    // One valid client (should be returned)
    $valid = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'expires_at' => null,
    ]);

    // One inactive client (should be excluded)
    ApiClient::factory()->inactive()->create(['organization_id' => $org->id]);

    // One expired client (should be excluded)
    ApiClient::factory()->expired()->create(['organization_id' => $org->id]);

    $ids = ApiClient::active()
        ->where('organization_id', $org->id)
        ->pluck('id')
        ->all();

    expect($ids)->toContain($valid->id);
    expect(count($ids))->toBe(1);
});
