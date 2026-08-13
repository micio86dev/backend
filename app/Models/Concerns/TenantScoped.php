<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\Tenancy\MissingTenantContextException;
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

            // Table-qualified (backoffice-missing-pages D6): an unqualified
            // 'organization_id' is ambiguous the moment a caller JOINs this
            // model's query to another table that also carries an
            // organization_id column (e.g. EvaluationIndexQuery joining
            // Evaluation to participants/projects) — Postgres rejects the
            // query outright ("column reference is ambiguous") rather than
            // guessing which table was meant. Qualifying with the model's
            // own table is a strict no-op for every existing non-join query.
            $query->where($query->getModel()->getTable().'.organization_id', $resolver->getOrgId());
        });

        // Creating listener — tamper-proof organization_id stamp.
        // Runs BEFORE the INSERT; unconditionally replaces any caller-supplied
        // organization_id with the resolver value (NOT "set only if null").
        //
        // D4(a): if no tenant context has been established (resolver.orgId is
        // null), fail closed by throwing instead of stamping null. This is
        // deliberately NOT a "set only if null" guard — there is no code path
        // here that leaves organization_id untouched. The caller's context-
        // establishment mechanism (e.g. App\Support\Tenancy\TenantContextScope
        // for queued jobs, or TenantContext/TenantContextCandidate for HTTP)
        // is responsible for ensuring a valid org is set BEFORE this listener runs.
        static::creating(function (Model $model): void {
            /** @var TenantResolver $resolver */
            $resolver = app(TenantResolver::class);

            $orgId = $resolver->getOrgId();

            if ($orgId === null) {
                throw new MissingTenantContextException(static::class);
            }

            // Use setAttribute() so PHPStan knows we are setting a dynamic property
            // via Eloquent's magic setter, not a typed class property.
            $model->setAttribute('organization_id', $orgId);
        });
    }
}
