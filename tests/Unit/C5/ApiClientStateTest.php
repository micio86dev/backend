<?php

declare(strict_types=1);

/**
 * ApiClient::state() equivalence tests (C5 — M2M API Authentication, design D3).
 *
 * `state()` is a second expression of the same predicate `scopeActive` uses
 * (`is_active = true AND (expires_at IS NULL OR expires_at > now())`). A SQL
 * `WHERE` and a PHP boolean cannot literally share code, so this test converts
 * "hope they agree" into "CI fails when they stop agreeing": across five rows,
 * `state() === 'active'` iff the row is returned by `ApiClient::active()->get()`.
 *
 * Uses RefreshDatabase, mirroring ApiClientActiveScopeTest.php in this same
 * directory (Unit/C5 does not use RefreshDatabase by default).
 *
 * design.md D3
 */

use App\Models\ApiClient;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('state rows', [
    'active, no expiry' => [true, null, 'active'],
    'active, future expiry' => [true, 'future', 'active'],
    'active, past expiry (expired)' => [true, 'past', 'expired'],
    'revoked, future expiry' => [false, 'future', 'revoked'],
    'revoked, past expiry' => [false, 'past', 'revoked'],
]);

test('ApiClient::state() agrees with scopeActive on every row', function (bool $isActive, ?string $expiresAt, string $expectedState): void {
    $org = Organization::factory()->create();

    $resolvedExpiresAt = match ($expiresAt) {
        'future' => now()->addYear(),
        'past' => now()->subYear(),
        default => null,
    };

    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => $isActive,
        'expires_at' => $resolvedExpiresAt,
    ]);

    $returnedByActiveScope = ApiClient::active()->where('id', $client->id)->exists();

    expect($client->state())->toBe($expectedState);
    expect($client->state() === 'active')->toBe($returnedByActiveScope);
})->with('state rows');
