<?php

declare(strict_types=1);

/**
 * UserAdminReader — the single sanctioned path to obtain a User for the
 * user-management surface (backoffice-missing-pages D4).
 *
 * Mirrors AdminParticipantReader (C11 D1): org filter FIRST, and a platform
 * superadmin who happens to carry an organization_id is invisible here —
 * not demotable, not deactivatable, not listable — because the query
 * predicate excludes is_superadmin=true unconditionally.
 *
 * REQ: Cross-Tenant Access Returns 404, Not 403
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use App\Support\Users\UserAdminReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('a cross-org user id throws ModelNotFoundException', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $userInOrgB = User::factory()->create(['organization_id' => $orgB->id]);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);

    $reader = new UserAdminReader($resolver);

    expect(fn () => $reader->read($userInOrgB->id))->toThrow(ModelNotFoundException::class);
});

test('a same-org platform superadmin row is invisible', function (): void {
    $org = Organization::factory()->create();
    $superadmin = User::factory()->create(['organization_id' => $org->id, 'is_superadmin' => true]);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $reader = new UserAdminReader($resolver);

    expect(fn () => $reader->read($superadmin->id))->toThrow(ModelNotFoundException::class);
});

test('a same-org non-superadmin user id resolves', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id, 'is_superadmin' => false]);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $reader = new UserAdminReader($resolver);

    $resolved = $reader->read($user->id);

    expect($resolved->id)->toBe($user->id);
});
