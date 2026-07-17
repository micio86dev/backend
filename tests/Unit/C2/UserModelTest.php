<?php

/**
 * User model unit tests (C2 — JWT auth).
 *
 * Asserts structural invariants:
 * - implements JWTSubject (getJWTIdentifier, getJWTCustomClaims)
 * - uses HasRoles (Spatie)
 * - organization_id and is_superadmin are NOT in $fillable
 * - organization() returns a BelongsTo relation
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

test('User implements JWTSubject', function (): void {
    $user = new User;

    expect($user)->toBeInstanceOf(JWTSubject::class);
});

test('getJWTIdentifier returns the user id', function (): void {
    $user = new User;
    $user->id = 42;

    expect($user->getJWTIdentifier())->toBe(42);
});

test('getJWTCustomClaims includes organization_id key', function (): void {
    $user = new User;
    $user->organization_id = 7;

    $claims = $user->getJWTCustomClaims();

    expect($claims)->toHaveKey('organization_id');
    expect($claims['organization_id'])->toBe(7);
});

test('organization_id is NOT in fillable', function (): void {
    $user = new User;
    $fillable = $user->getFillable();

    expect($fillable)->not->toContain('organization_id');
});

test('is_superadmin is NOT in fillable', function (): void {
    $user = new User;
    $fillable = $user->getFillable();

    expect($fillable)->not->toContain('is_superadmin');
});

test('organization() returns a BelongsTo relation', function (): void {
    $user = new User;
    $relation = $user->organization();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Organization::class);
});

test('User uses HasRoles trait', function (): void {
    $traits = class_uses_recursive(User::class);

    expect($traits)->toContain(HasRoles::class);
});
