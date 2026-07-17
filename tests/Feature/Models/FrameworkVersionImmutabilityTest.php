<?php

/**
 * RED — 4.3: FrameworkVersion immutability guard (C3).
 *
 * Asserts that a locked FrameworkVersion CANNOT be deleted OR mutated.
 * The immutabilityGuard blocks both operations when is_locked=true.
 * C4 activates the lock; C3 ships the guard.
 *
 * Uses TenantResolver::setOrgId() to satisfy the TenantScoped creating listener,
 * then sets bypass=true to avoid TenantScoped global scope filtering.
 *
 * Refs spec: "A referenced FrameworkVersion cannot be deleted or mutated".
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;

/** Helper: create FrameworkVersion with org scoping configured. */
function makeLockedFV(Organization $org, array $attrs = []): FrameworkVersion
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return FrameworkVersion::create(array_merge([
        'version' => 'v1',
        'is_locked' => true,
    ], $attrs));
}

test('deleting a locked FrameworkVersion throws and record remains intact', function (): void {
    $org = Organization::factory()->create();
    $fv = makeLockedFV($org, ['is_locked' => true]);

    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true); // bypass scope to find record after guard

    expect(fn () => $fv->delete())->toThrow(RuntimeException::class);

    // Record must still exist in DB (use bypass to skip TenantScoped scope)
    expect(FrameworkVersion::withoutGlobalScopes()->find($fv->id))->not->toBeNull();
});

test('updating a locked FrameworkVersion throws and record remains unchanged', function (): void {
    $org = Organization::factory()->create();
    $fv = makeLockedFV($org, ['is_locked' => true, 'label' => 'Original']);

    expect(fn () => $fv->update(['label' => 'Modified']))->toThrow(RuntimeException::class);

    // Record must be unchanged
    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true);
    $fresh = FrameworkVersion::withoutGlobalScopes()->find($fv->id);
    expect($fresh->label)->toBe('Original');
});

test('unlocked FrameworkVersion can be deleted', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => 'v1', 'is_locked' => false]);

    $id = $fv->id;
    $fv->delete();

    $resolver->setBypass(true);
    expect(FrameworkVersion::withoutGlobalScopes()->find($id))->toBeNull();
});

test('unlocked FrameworkVersion can be updated', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => 'v1', 'is_locked' => false, 'label' => 'Draft']);

    $fv->update(['label' => 'Updated']);

    $resolver->setBypass(true);
    expect(FrameworkVersion::withoutGlobalScopes()->find($fv->id)->label)->toBe('Updated');
});
