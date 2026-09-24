<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use App\Models\ApiClient;
use App\Support\PublicApi\ApiMode;
use App\Support\PublicApi\Problem;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps tenancy AND test/live mode for the BEAI Public API (`/v1`).
 *
 * MUST run AFTER `AuthenticatePublicApi` — relies on the `ApiClient` already
 * being set on the `api-m2m` guard. A near-mirror of
 * `App\Http\Middleware\TenantContextM2m` (same fail-closed invariants, same
 * intentional bypass-then-org-then-team ordering — see that class's
 * docblock for the reasoning), with one addition: this middleware ALSO
 * stamps `App\Support\PublicApi\ApiMode` from the client's `mode` column,
 * which `TenantContextM2m` has no equivalent of (the internal M2M surface
 * has no test/live distinction).
 *
 * Fail-closed invariants (identical to `TenantContextM2m`):
 * - null client (should be unreachable — `AuthenticatePublicApi` always runs
 *   first — but checked explicitly rather than trusted) → 401
 * - null/invalid organization_id on client → 401
 *
 * REQ-4, REQ-T1 / design §TenantContextM2m
 */
final class PublicApiTenantContext
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly PermissionRegistrar $registrar,
        private readonly ApiMode $apiMode,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiClient|null $client */
        $client = Auth::guard('api-m2m')->user();

        if ($client === null) {
            return $this->unauthenticated($request);
        }

        $orgId = $client->organization_id;

        if ($orgId < 1) {
            return $this->unauthenticated($request);
        }

        // Intentional order (same hardening as TenantContextM2m):
        // 1. Clear any stale bypass flag FIRST.
        // 2. Stamp org from client record.
        // 3. Scope permissions team.
        // 4. Stamp the live/test mode this request runs under.
        $this->resolver->setBypass(false);
        $this->resolver->setOrgId($orgId);
        $this->registrar->setPermissionsTeamId($orgId);
        $this->apiMode->set($client->mode);

        return $next($request);
    }

    private function unauthenticated(Request $request): Response
    {
        return Problem::make(
            $request,
            401,
            'invalid_api_key',
            'Invalid API key',
            extraHeaders: ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
