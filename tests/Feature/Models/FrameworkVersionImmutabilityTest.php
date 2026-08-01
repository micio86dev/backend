<?php

/**
 * FrameworkVersion immutability guard (C3 + C4 update).
 *
 * Asserts that a locked FrameworkVersion CANNOT be deleted OR mutated.
 * The immutabilityGuard blocks both operations when is_locked=true.
 *
 * C4 change: RuntimeException replaced by LockedFrameworkVersionException (renders HTTP 422).
 * C4 change: is_locked removed from $fillable — must use explicit property assignment.
 *
 * Refs spec: "A referenced FrameworkVersion cannot be deleted or mutated".
 */

use App\Exceptions\LockedFrameworkVersionException;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;

/** Helper: create a locked FrameworkVersion with org scoping configured. */
function makeLockedFV(Organization $org, array $attrs = []): FrameworkVersion
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // is_locked is NOT fillable (C4 design) — use explicit property assignment (Pattern A).
    $fv = FrameworkVersion::create(array_merge(['version' => 'v1'], $attrs));
    $fv->is_locked = true;
    $fv->save();

    return $fv->fresh();
}

test('deleting a locked FrameworkVersion throws and record remains intact', function (): void {
    $org = Organization::factory()->create();
    $fv = makeLockedFV($org);

    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true); // bypass scope to find record after guard

    // C4: LockedFrameworkVersionException (not bare RuntimeException)
    expect(fn () => $fv->delete())->toThrow(LockedFrameworkVersionException::class);

    // Record must still exist in DB (use bypass to skip TenantScoped scope)
    expect(FrameworkVersion::withoutGlobalScopes()->find($fv->id))->not->toBeNull();
});

test('updating a locked FrameworkVersion throws and record remains unchanged', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $fv = FrameworkVersion::create(['version' => 'v1', 'label' => 'Original']);
    $fv->is_locked = true;
    $fv->save();
    $fv = $fv->fresh();

    // C4: LockedFrameworkVersionException (not bare RuntimeException)
    expect(fn () => $fv->update(['label' => 'Modified']))->toThrow(LockedFrameworkVersionException::class);

    // Record must be unchanged
    $resolver->setBypass(true);
    $fresh = FrameworkVersion::withoutGlobalScopes()->find($fv->id);
    expect($fresh->label)->toBe('Original');
});

test('unlocked FrameworkVersion can be deleted', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::create(['version' => 'v1']);

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

    $fv = FrameworkVersion::create(['version' => 'v1', 'label' => 'Draft']);

    $fv->update(['label' => 'Updated']);

    $resolver->setBypass(true);
    expect(FrameworkVersion::withoutGlobalScopes()->find($fv->id)->label)->toBe('Updated');
});
