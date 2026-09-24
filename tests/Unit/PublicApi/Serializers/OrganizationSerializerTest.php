<?php

declare(strict_types=1);

/**
 * `App\PublicApi\Serializers\OrganizationSerializer` — public-api step 4,
 * SPEC.md §3.3 "`GET /v1/organization` — `id, name, mode` (of the key),
 * `default_language?`, `allowed_domains`, `created_at`" and
 * `openapi.yaml`'s `Organization` schema.
 */

use App\Enums\ApiKeyMode;
use App\Models\Organization;
use App\PublicApi\Serializers\OrganizationSerializer;

test('serializes exactly the contract field set, id prefixed org_, mode from the given key mode', function (): void {
    $org = Organization::factory()->create([
        'name' => 'Acme Corp',
        'allowed_domains' => ['acme.example', 'hr.acme.example'],
    ]);

    $array = OrganizationSerializer::toArray($org, ApiKeyMode::Live);

    expect(array_keys($array))->toEqualCanonicalizing(['id', 'name', 'mode', 'allowed_domains', 'created_at']);
    expect($array['id'])->toBe('org_'.$org->public_id);
    expect($array['name'])->toBe('Acme Corp');
    expect($array['mode'])->toBe('live');
    expect($array['allowed_domains'])->toBe(['acme.example', 'hr.acme.example']);
    expect($array['created_at'])->toBe($org->created_at?->toISOString());
});

test('mode reflects the test key when given ApiKeyMode::Test, independent of any organization column', function (): void {
    $org = Organization::factory()->create();

    $array = OrganizationSerializer::toArray($org, ApiKeyMode::Test);

    expect($array['mode'])->toBe('test');
});

test('allowed_domains renders [] when the column is null, never null itself', function (): void {
    $org = Organization::factory()->create(['allowed_domains' => null]);

    $array = OrganizationSerializer::toArray($org, ApiKeyMode::Live);

    expect($array['allowed_domains'])->toBe([]);
});

test('created_at is ISO 8601 with a Z suffix', function (): void {
    $org = Organization::factory()->create();

    $array = OrganizationSerializer::toArray($org, ApiKeyMode::Live);

    expect($array['created_at'])->toMatch('/Z$/');
});
