<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection for the now-publicly-routable POST /api/auth/refresh
 * (design D5, D8). Two independent checks:
 *
 * 1. Requires the custom `X-BEAI-Refresh: 1` header. A custom header makes
 *    the request non-simple, forcing the browser to send a CORS preflight
 *    first; our preflight (config/cors.php) answers only allowlisted
 *    origins, so an attacker's origin never receives permission and the
 *    browser never sends the real request. A <form> POST or a simple fetch
 *    cannot set this header at all.
 * 2. A server-side check that any PRESENT `Origin` header is in the CORS
 *    allowlist. NOT redundant with (1): Laravel's HandleCors does not BLOCK
 *    a disallowed non-preflight cross-origin request — it only omits
 *    Access-Control-Allow-Origin, so the server has already executed the
 *    state change by the time the browser hides the response. This check
 *    makes the defense independent of browser cooperation.
 */
final class RequireRefreshCsrfHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-BEAI-Refresh') !== '1') {
            return response()->json(['error' => 'csrf_header_required'], Response::HTTP_FORBIDDEN);
        }

        $origin = $request->header('Origin');

        if ($origin !== null && ! in_array($origin, (array) config('cors.allowed_origins'), true)) {
            return response()->json(['error' => 'origin_not_allowed'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
