<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Participant;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * TenantContextCandidate middleware (C6 — Participant + SSO Ingress).
 *
 * Stamps TenantResolver from the authenticated Participant's organization_id.
 * MUST run AFTER auth:api-candidate — relies on the Participant already being loaded.
 *
 * Ordering (mirrors TenantContextM2m — intentional reversal of C2 TenantContext):
 *   1. setBypass(false)  ← FIRST — clears any stale bypass=true
 *   2. setOrgId(orgId)   ← SECOND — stamps the org from the participant DB record
 *   3. setPermissionsTeamId(orgId)
 *
 * The bypass flag is cleared FIRST as a belt-and-suspenders hardening: no stale
 * bypass=true can coexist with a freshly-stamped orgId in a request-scoped resolver.
 *
 * Fail-closed invariants:
 * - null participant (guard returned null) → 401
 * - null organization_id on participant   → 401
 *
 * org ALWAYS comes from the participant DB record — NEVER from JWT claims.
 * This prevents a tampered org claim in the candidate JWT from crossing org boundaries.
 *
 * REQ: TenantContextCandidate — Third Org-Resolution Path
 */
final class TenantContextCandidate
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Participant|null $participant */
        $participant = Auth::guard('api-candidate')->user();

        // Fail-closed: no authenticated candidate means no org context.
        if ($participant === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $orgId = $participant->organization_id;

        // Fail-closed: a participant without a valid organization_id is invalid.
        // organization_id is a positive FK; a missing/zero value means broken hydration.
        if ($orgId < 1) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        // Intentional order (mirrors TenantContextM2m — reversal of C2 TenantContext):
        // 1. Clear any stale bypass flag FIRST.
        // 2. Stamp org from participant DB record (NEVER from claims).
        // 3. Scope permissions team.
        $this->resolver->setBypass(false);
        $this->resolver->setOrgId($orgId);
        $this->registrar->setPermissionsTeamId($orgId);

        return $next($request);
    }
}
