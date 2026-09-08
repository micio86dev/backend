<?php

declare(strict_types=1);

/**
 * RED — PlatformUserReader (platform-user-management D1).
 *
 * The mirror image of `UserAdminReader`, and deliberately a SEPARATE class
 * rather than a flag on that one. `UserAdminReader` carries its invariant in
 * its WHERE clause —
 *
 *   `is_superadmin = false` — a platform superadmin ... is invisible here:
 *   not demotable, not deactivatable, not listable.
 *
 * — precisely because a conditional is what gets forgotten. This reader
 * inverts the predicate, the two never meet, and an id from the wrong
 * population 404s on both with no branch to audit.
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Users\PlatformUserReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;

function platformUser(array $attributes = []): User
{
    return User::factory()->create([
        'organization_id' => null,
        'is_superadmin' => true,
        ...$attributes,
    ]);
}

test('it reaches a platform user', function (): void {
    $superadmin = platformUser();

    expect(app(PlatformUserReader::class)->read((int) $superadmin->id)->id)->toBe($superadmin->id);
});

test('an ORGANISATION user is unreachable by id', function (): void {
    $org = Organization::factory()->create();
    $orgUser = User::factory()->create(['organization_id' => $org->id, 'is_superadmin' => false]);

    // 404, never 403: a 403 would confirm the row exists, which is an
    // existence oracle — the same reasoning UserAdminReader records.
    expect(fn () => app(PlatformUserReader::class)->read((int) $orgUser->id))
        ->toThrow(ModelNotFoundException::class);
});

test('a superadmin that still carries an organization is NOT a platform user', function (): void {
    // Both predicates, always together. Either one alone lets a row through
    // that the other would have refused.
    $org = Organization::factory()->create();
    $hybrid = User::factory()->create(['organization_id' => $org->id, 'is_superadmin' => true]);

    expect(fn () => app(PlatformUserReader::class)->read((int) $hybrid->id))
        ->toThrow(ModelNotFoundException::class);
});

test('an org user with a null organization is NOT a platform user either', function (): void {
    $orphan = User::factory()->create(['organization_id' => null, 'is_superadmin' => false]);

    expect(fn () => app(PlatformUserReader::class)->read((int) $orphan->id))
        ->toThrow(ModelNotFoundException::class);
});

test('the list carries every platform user and nobody else', function (): void {
    $org = Organization::factory()->create();
    $a = platformUser();
    $b = platformUser();
    $orgUser = User::factory()->create(['organization_id' => $org->id]);

    $ids = app(PlatformUserReader::class)->listQuery()->pluck('id');

    expect($ids)->toContain($a->id)->toContain($b->id);
    expect($ids)->not->toContain($orgUser->id);
});

test('a DEACTIVATED platform user is still listed', function (): void {
    // Hiding them would make reactivating one impossible, which is the same
    // reason the org surface lists its deactivated users.
    $active = platformUser();
    $inactive = platformUser(['deactivated_at' => now()]);

    $ids = app(PlatformUserReader::class)->listQuery()->pluck('id');

    expect($ids)->toContain($active->id)->toContain($inactive->id);
});
