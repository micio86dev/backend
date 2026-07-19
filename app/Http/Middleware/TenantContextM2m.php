<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * TenantContextM2m middleware (C5 — M2M API Authentication).
 *
 * Stamps TenantResolver from the authenticated ApiClient's organization_id.
 * MUST run AFTER auth:api-m2m — relies on the ApiClient already being loaded.
 *
 * Ordering (intentional reversal of C2 TenantContext):
 *   1. setBypass(false)  ← FIRST — clears any stale bypass=true
 *   2. setOrgId(orgId)   ← SECOND — stamps the org from the client record
 *   3. setPermissionsTeamId(orgId)
 *
 * This is NOT a mirror of C2's TenantContext — it deliberately reverses the
 * C2 order (setOrgId then setBypass) as a belt-and-suspenders hardening:
 * clearing the bypass flag first guarantees no stale bypass=true can coexist
 * with a freshly-stamped orgId, even in a request-scoped resolver.
 *
 * Fail-closed invariants:
 * - null client (guard returned null) → 401
 * - null organization_id on client   → 401
 *
 * org ALWAYS comes from the client DB record — never from request input.
 *
 * REQ-4, REQ-T1 / design §TenantContextM2m
 */
final class TenantContextM2m
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiClient|null $client */
        $client = Auth::guard('api-m2m')->user();

        // Fail-closed: no authenticated M2M client means no org context.
        if ($client === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $orgId = $client->organization_id;

        // Fail-closed: a client without an organization_id is a misconfigured credential.
        if ($orgId === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        // Intentional order (C5 hardening — reversal of C2 TenantContext):
        // 1. Clear any stale bypass flag FIRST.
        // 2. Stamp org from client record.
        // 3. Scope permissions team.
        $this->resolver->setBypass(false);
        $this->resolver->setOrgId($orgId);
        $this->registrar->setPermissionsTeamId($orgId);

        return $next($request);
    }
}
