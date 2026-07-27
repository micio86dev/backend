<?php

declare(strict_types=1);

/**
 * TenantContextScope unit tests (queued-job-tenancy PR1).
 *
 * TenantContextScope::runFor() establishes tenant context explicitly for the
 * duration of a callback and restores the previous (orgId, bypass, teamId)
 * triple in a finally block — regardless of how the callback exits.
 *
 * REQ: Queued-Job Tenant Context Establishment (openspec/specs/tenancy/spec.md)
 */

use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\PermissionRegistrar;

it('nested runFor restores the outer org on inner return, not the original nor null', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(1);
    $resolver->setBypass(false);

    TenantContextScope::runFor(2, function () use ($resolver): void {
        expect($resolver->getOrgId())->toBe(2);

        TenantContextScope::runFor(3, function () use ($resolver): void {
            expect($resolver->getOrgId())->toBe(3);
        });

        expect($resolver->getOrgId())->toBe(2, 'inner runFor must restore the OUTER org (2), not the pre-outer value (1)');
    });

    expect($resolver->getOrgId())->toBe(1, 'outermost runFor must restore the value present before it ran');
});

it('exception inside the closure still restores the previous org via finally', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(5);
    $resolver->setBypass(false);

    $caught = null;

    try {
        TenantContextScope::runFor(9, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull('the original exception must propagate, not be swallowed');
    expect($caught->getMessage())->toBe('boom');
    expect($resolver->getOrgId())->toBe(5, 'org must be restored even when the closure throws');
});

it('returns the callback return value', function (): void {
    $result = TenantContextScope::runFor(4, fn () => 'callback-result');

    expect($result)->toBe('callback-result');
});

it('isBypass() is false inside runFor even when true outside, and is restored after', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(1);
    $resolver->setBypass(true);

    TenantContextScope::runFor(7, function () use ($resolver): void {
        expect($resolver->isBypass())->toBeFalse();
    });

    expect($resolver->isBypass())->toBeTrue('bypass must be restored to its previous value (true) after runFor');
});

it('sets the Spatie permissions team id to orgId inside and restores the previous value after', function (): void {
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(11);

    TenantContextScope::runFor(22, function () use ($registrar): void {
        expect($registrar->getPermissionsTeamId())->toBe(22);
    });

    expect($registrar->getPermissionsTeamId())->toBe(11, 'team id must be restored to its previous value (11) after runFor');
});

it('rejects orgId < 1 with InvalidArgumentException before any state change', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(6);
    $resolver->setBypass(false);

    expect(fn () => TenantContextScope::runFor(0, fn () => null))
        ->toThrow(InvalidArgumentException::class);

    expect($resolver->getOrgId())->toBe(6, 'a rejected call must not mutate resolver state');
});
