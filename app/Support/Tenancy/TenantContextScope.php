<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * TenantContextScope — explicitly establishes tenant context for the
 * duration of a callback and restores the previous state afterwards.
 *
 * Replaces reliance on ambient TenantResolver state (which Queue::before
 * resets to orgId=null before every queued job — TenancyServiceProvider.php)
 * with an explicit, closure-scoped boundary. Usable from jobs, listeners,
 * console commands, and tests — anywhere outside the HTTP request lifecycle
 * where TenantContext/TenantContextCandidate do not run.
 *
 * Restore semantics: the PREVIOUS (orgId, bypass, teamId) triple is restored,
 * NOT a hard reset to null. A nested runFor() must return control to the
 * OUTER context, not to null — this supports reentrancy and reuse outside
 * the queue worker (e.g. a future admin/console path calling runFor mid-request).
 *
 * REQ: Queued-Job Tenant Context Establishment (openspec/specs/tenancy/spec.md)
 */
final class TenantContextScope
{
    /**
     * Run $callback with tenant context established for $orgId.
     *
     * Ordering on entry: setBypass(false) -> setOrgId($orgId) ->
     * setPermissionsTeamId($orgId) — mirrors TenantContextCandidate.php:67-69.
     * The previous (orgId, bypass, teamId) triple is always restored in a
     * finally block, even if $callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runFor(int $orgId, Closure $callback): mixed
    {
        if ($orgId < 1) {
            throw new InvalidArgumentException("TenantContextScope::runFor() requires a positive orgId, got {$orgId}.");
        }

        /** @var TenantResolver $resolver */
        $resolver = app(TenantResolver::class);
        /** @var PermissionRegistrar $registrar */
        $registrar = app(PermissionRegistrar::class);

        $previousOrgId = $resolver->getOrgId();
        $previousBypass = $resolver->isBypass();
        $previousTeamId = $registrar->getPermissionsTeamId();

        $resolver->setBypass(false);
        $resolver->setOrgId($orgId);
        $registrar->setPermissionsTeamId($orgId);

        try {
            return $callback();
        } finally {
            $resolver->setOrgId($previousOrgId);
            $resolver->setBypass($previousBypass);
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
