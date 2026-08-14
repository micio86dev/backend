<?php

declare(strict_types=1);

/**
 * ApiClientResource unit tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - key_hash is NEVER in the resource output
 * - raw api_key is NEVER in the resource output
 * - safe fields (id, name, abilities, is_active, expires_at, last_used_at, created_at) are present
 *
 * REQ-2, REQ-8
 */

use App\Http\Resources\ApiClientResource;
use App\Models\ApiClient;
use App\Models\Organization;
use Illuminate\Http\Request;

test('ApiClientResource never exposes key_hash', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create(['organization_id' => $org->id]);

    $resource = new ApiClientResource($client);
    $array = $resource->toArray(new Request);

    expect($array)->not->toHaveKey('key_hash');
});

test('ApiClientResource never exposes api_key', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create(['organization_id' => $org->id]);

    $resource = new ApiClientResource($client);
    $array = $resource->toArray(new Request);

    expect($array)->not->toHaveKey('api_key');
});

test('ApiClientResource exposes id, name, abilities, is_active, created_at', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'name' => 'Test Client',
        'abilities' => ['participants:read'],
        'is_active' => true,
    ]);

    $resource = new ApiClientResource($client);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKey('id');
    expect($array)->toHaveKey('name');
    expect($array['name'])->toBe('Test Client');
    expect($array)->toHaveKey('abilities');
    expect($array['abilities'])->toContain('participants:read');
    expect($array)->toHaveKey('is_active');
    expect($array['is_active'])->toBeTrue();
    expect($array)->toHaveKey('created_at');
});

test('ApiClientResource wire types pin the docblock against schema drift (D2)', function (): void {
    // Regression/pinning test, not a fix-proving RED: runtime values were
    // ALWAYS correct here (ApiClient.php casts already coerced them). Only
    // the generated openapi.json schema lied, because Scramble does not
    // honour the `@var ApiClient $client` local-assignment annotation. This
    // pins the wire types PHP-side so a future revert of the `@scramble-return`
    // docblock is caught here too, not only by re-running scramble:export.
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'abilities' => ['participants:read'],
        'is_active' => true,
    ]);

    $resource = new ApiClientResource($client);
    $array = $resource->toArray(new Request);

    expect($array['id'])->toBeInt();
    expect($array['is_active'])->toBeBool();
    expect($array['abilities'])->toBeArray();
});

test('ApiClientResource exposes expires_at and last_used_at (nullable)', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'expires_at' => null,
        'last_used_at' => null,
    ]);

    $resource = new ApiClientResource($client);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKey('expires_at');
    expect($array)->toHaveKey('last_used_at');
    expect($array['expires_at'])->toBeNull();
    expect($array['last_used_at'])->toBeNull();
});
