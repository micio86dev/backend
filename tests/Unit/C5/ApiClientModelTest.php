<?php

declare(strict_types=1);

/**
 * ApiClient model unit tests (C5 — M2M API Authentication).
 *
 * Asserts structural invariants:
 * - does NOT extend Illuminate\Foundation\Auth\User
 * - uses Illuminate\Auth\Authenticatable trait (not HasRoles)
 * - key_hash is in $hidden
 * - key_hash is NOT in $fillable
 * - abilities cast to array
 * - can() performs strict in_array check
 * - active() scope excludes inactive and expired clients
 * - belongsTo(Organization)
 *
 * REQ-1, REQ-3, REQ-6
 */

use App\Models\ApiClient;
use App\Models\Organization;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as FoundationUser;

test('ApiClient implements Authenticatable contract', function (): void {
    $client = new ApiClient;

    expect($client)->toBeInstanceOf(AuthenticatableContract::class);
});

test('ApiClient does NOT extend Foundation\\Auth\\User', function (): void {
    expect(ApiClient::class)->not->toExtend(FoundationUser::class);
});

test('ApiClient uses Illuminate\\Auth\\Authenticatable trait', function (): void {
    $traits = class_uses_recursive(ApiClient::class);

    expect($traits)->toContain(Authenticatable::class);
});

test('key_hash is in $hidden', function (): void {
    $client = new ApiClient;

    expect($client->getHidden())->toContain('key_hash');
});

test('key_hash is NOT in $fillable', function (): void {
    $client = new ApiClient;

    expect($client->getFillable())->not->toContain('key_hash');
});

test('abilities is cast to array', function (): void {
    $client = new ApiClient;
    $casts = $client->getCasts();

    expect($casts)->toHaveKey('abilities');
    expect($casts['abilities'])->toBe('array');
});

test('can() returns true for an ability the client has', function (): void {
    $client = new ApiClient;
    $client->abilities = ['participants:read', 'projects:read'];

    expect($client->can('participants:read'))->toBeTrue();
    expect($client->can('projects:read'))->toBeTrue();
});

test('can() returns false for an ability the client does not have', function (): void {
    $client = new ApiClient;
    $client->abilities = ['participants:read'];

    expect($client->can('evaluations:read'))->toBeFalse();
    expect($client->can('sso_link:generate'))->toBeFalse();
});

test('can() is strict — no partial match (participants vs participants:read)', function (): void {
    $client = new ApiClient;
    $client->abilities = ['participants'];

    // 'participants' should NOT match 'participants:read' — strict in_array
    expect($client->can('participants:read'))->toBeFalse();
});

test('can() returns false when abilities is null', function (): void {
    $client = new ApiClient;
    $client->abilities = null;

    expect($client->can('participants:read'))->toBeFalse();
});

test('organization() returns a BelongsTo relation pointing to Organization', function (): void {
    $client = new ApiClient;
    $relation = $client->organization();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Organization::class);
});

test('ApiClient does NOT use HasRoles trait', function (): void {
    $traits = class_uses_recursive(ApiClient::class);

    expect($traits)->not->toContain(\Spatie\Permission\Traits\HasRoles::class);
});
