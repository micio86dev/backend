<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant isolation trait.
 *
 * Attach this trait (via TenantModel) to any Eloquent model that belongs to
 * an organization. Two isolation invariants are enforced:
 *
 * 1. Global scope: every query is automatically filtered to the current
 *    tenant's organization_id. Rows from other orgs are invisible.
 *    Bypass is skipped when resolver->isBypass() === true (superadmin).
 *
 * 2. Tamper-proof create stamp: the `creating` listener UNCONDITIONALLY
 *    overwrites organization_id with the resolver value, even if the caller
 *    explicitly passed a foreign org_id. This prevents cross-tenant writes
 *    from request payload manipulation.
 */
trait TenantScoped
{
    /**
     * Boot the TenantScoped trait.
     *
     * Registers:
     *   - addGlobalScope: filters queries by organization_id (skip when bypass=true)
     *   - creating: unconditionally stamps organization_id from resolver
     */
    public static function bootTenantScoped(): void
    {
        // Global scope — applied to every SELECT on this model.
        static::addGlobalScope('tenant', function (Builder $query): void {
            /** @var TenantResolver $resolver */
            $resolver = app(TenantResolver::class);

            if ($resolver->isBypass()) {
                // Superadmin bypass: no filter applied — all orgs visible.
                return;
            }

            $query->where('organization_id', $resolver->getOrgId());
        });

        // Creating listener — tamper-proof organization_id stamp.
        // Runs BEFORE the INSERT; unconditionally replaces any caller-supplied
        // organization_id with the resolver value (NOT "set only if null").
        static::creating(function (Model $model): void {
            /** @var TenantResolver $resolver */
            $resolver = app(TenantResolver::class);
            $model->organization_id = $resolver->getOrgId();
        });
    }
}
