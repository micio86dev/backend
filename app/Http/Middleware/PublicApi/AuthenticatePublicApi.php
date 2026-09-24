<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use App\Support\PublicApi\ApiKeyResolver;
use App\Support\PublicApi\Problem;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the BEAI Public API (`/v1`) — SPEC.md §3.1.
 *
 * Runs AFTER `RejectApiKeyInQuery`. Resolves the bearer key via
 * `App\Support\PublicApi\ApiKeyResolver` (shared with the existing `api-m2m`
 * guard closure — see that class's docblock), then applies the two checks
 * that are specific to THIS public surface and have no equivalent on the
 * internal M2M guard:
 *
 *   - No/invalid/revoked/expired key → `401 invalid_api_key` +
 *     `WWW-Authenticate: Bearer` (RFC 6750 §3 — a bearer-auth failure SHOULD
 *     carry this header; the internal M2M guard, never reached by a browser,
 *     does not bother).
 *   - An `Origin` header on a `live`-mode key → `401
 *     browser_origin_forbidden` (SPEC.md §3.1: "defense in depth, not the
 *     primary control" — a live secret key must never be usable from a
 *     browser context at all, so any `Origin` at all is refused, not just a
 *     known one). A `test`-mode key is exempt — SPEC.md §3.7 test mode is
 *     precisely the surface meant for local/browser experimentation.
 *
 * On success, sets the SAME `api-m2m` guard's resolved user (not a new
 * guard) so `App\Models\ApiClient::can()` and every downstream helper that
 * reads `Auth::guard('api-m2m')->user()` (`RequireScope`,
 * `PublicApiTenantContext`) work identically to the internal M2M surface —
 * `/v1` and `/api/m2m` are two DOORS onto the same credential, not two
 * credential systems.
 */
final class AuthenticatePublicApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with((string) $header, 'Bearer ')) {
            return $this->unauthorized($request);
        }

        $raw = substr((string) $header, 7);

        if ($raw === '') {
            return $this->unauthorized($request);
        }

        $client = ApiKeyResolver::resolve($raw);

        if ($client === null) {
            return $this->unauthorized($request);
        }

        if ($request->headers->has('Origin') && $client->mode === 'live') {
            return Problem::make(
                $request,
                401,
                'browser_origin_forbidden',
                'Browser origin forbidden',
                'Live-mode API keys cannot be used from a browser context. Use a test-mode key or call this API server-side.',
                extraHeaders: ['WWW-Authenticate' => 'Bearer'],
            );
        }

        Auth::guard('api-m2m')->setUser($client);
        $request->attributes->set('public_api.client', $client);

        return $next($request);
    }

    private function unauthorized(Request $request): JsonResponse
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
