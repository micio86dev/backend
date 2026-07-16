<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers middleware (task 7.7, D29).
 *
 * Applies defensive HTTP headers to every API response.
 * Registered globally in bootstrap/app.php.
 *
 * Notes:
 * - HSTS is applied only over HTTPS (checked via request->secure()).
 * - CSP is intentionally deferred to C2 (requires knowing auth routes and
 *   iframe origins for HeyGen/Tavus avatar providers).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // HSTS only over HTTPS — do not set over HTTP (local dev / CI health probes)
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // TODO(C2): Add Content-Security-Policy header once auth routes,
        // HeyGen/Tavus iframe origins, and the backoffice origin are known.

        return $response;
    }
}
