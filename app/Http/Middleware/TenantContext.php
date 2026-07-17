<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * TenantContext middleware (C2 — D2, D3).
 *
 * MUST run AFTER auth:api — it relies on the authenticated user already being
 * loaded by the JWT guard. Resolves organization_id exclusively from the DB
 * record ($user->organization_id); NEVER from the JWT claim.
 *
 * Fail-closed invariants:
 * - int org_id  → sets resolver.orgId + setPermissionsTeamId(orgId) → continue
 * - null org + is_superadmin === true  → bypass=true + setPermissionsTeamId(null) → continue
 * - null org + is_superadmin === false → HTTP 403 (account has no valid tenant context)
 *
 * A non-superadmin can NEVER trigger bypass — the bypass condition requires
 * BOTH a null DB organization_id AND the explicit is_superadmin boolean.
 */
final class TenantContext
{
    public function __construct(
        private readonly TenantResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // If no authenticated user (e.g. public routes like /api/auth/login),
        // pass through — auth:api on protected routes handles the 401.
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        /** @var User $user */
        // Resolve org from the DB record — NOT from the JWT claim (D3).
        $orgId = $user->organization_id;

        if ($orgId !== null) {
            // Normal tenant path: scope all queries to this org.
            $this->resolver->setOrgId($orgId);
            $this->resolver->setBypass(false);
            app(PermissionRegistrar::class)->setPermissionsTeamId($orgId);

            return $next($request);
        }

        // org_id is null — only superadmins may proceed with bypass.
        if ($user->is_superadmin === true) {
            // Explicit bypass: superadmin queries cross all tenants.
            // setPermissionsTeamId(null) is required for RBAC hygiene
            // (clears any stale team context from a previous request).
            $this->resolver->setBypass(true);
            $this->resolver->setOrgId(null);
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);

            return $next($request);
        }

        // FAIL-CLOSED: null org + is_superadmin=false is a misconfigured account.
        // Return 403 — not 401 (the user is authenticated, just not tenant-scoped).
        return response()->json(['message' => 'No tenant context.'], Response::HTTP_FORBIDDEN);
    }
}
