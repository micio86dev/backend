<?php

declare(strict_types=1);

/**
 * RED — 3.1: FrameworkVersion model changes (C4).
 *
 * (a) projects() returns real hasMany(Project::class) collection
 * (b) locked FV update throws LockedFrameworkVersionException (not RuntimeException)
 * (c) locked FV delete throws LockedFrameworkVersionException
 * (d) is_locked is NOT in $fillable
 * (e) organization_id IS in $fillable
 *
 * Refs spec: Tenant-Scoped FrameworkVersion Pin; spec scenario: locked FV update/delete blocked.
 */

use App\Exceptions\LockedFrameworkVersionException;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Relations\HasMany;

test('FrameworkVersion projects() returns a hasMany relation (not a placeholder)', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    // Verify it's a real HasMany (not the placeholder that returns empty)
    $relation = $fv->projects();
    expect($relation)->toBeInstanceOf(HasMany::class);

    // The collection should be empty (no projects yet), not non-existent
    expect($fv->projects()->count())->toBe(0);
});

test('updating a locked FrameworkVersion throws LockedFrameworkVersionException', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    expect(fn () => $fv->update(['label' => 'changed']))->toThrow(LockedFrameworkVersionException::class);
});

test('deleting a locked FrameworkVersion throws LockedFrameworkVersionException', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    expect(fn () => $fv->delete())->toThrow(LockedFrameworkVersionException::class);
});

test('is_locked is NOT in FrameworkVersion fillable', function (): void {
    $fv = new FrameworkVersion;
    expect($fv->getFillable())->not->toContain('is_locked');
});

test('organization_id IS in FrameworkVersion fillable', function (): void {
    $fv = new FrameworkVersion;
    expect($fv->getFillable())->toContain('organization_id');
});
