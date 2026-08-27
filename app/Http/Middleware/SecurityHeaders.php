<?php

namespace App\Http\Middleware;

use App\Support\Http\BackofficeOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers middleware (task 7.7, D29; CSP updated in C2).
 *
 * Applies defensive HTTP headers to every API response.
 * Registered globally in bootstrap/app.php.
 *
 * Notes:
 * - HSTS is applied only over HTTPS (checked via request->secure()).
 * - CSP frame-ancestors and connect-src are built from services.backoffice_origin
 *   (BACKOFFICE_ORIGIN env — read via config(), not env(), as required by Larastan).
 *   The origin must be a non-empty, non-wildcard explicit HTTPS origin.
 *   If unset or invalid, a safe block-all CSP default is used.
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

        // C2: Content-Security-Policy built from the backoffice_origin config value.
        // The backoffice SPA origin is the only consumer of the API from a browser context.
        $response->headers->set('Content-Security-Policy', $this->buildCsp());

        return $response;
    }

    /**
     * Build the Content-Security-Policy header value.
     *
     * frame-ancestors: prevents clickjacking — only our backoffice may embed API responses.
     * connect-src: restricts which origins JS can make XHR/fetch to from the backoffice.
     *
     * The origin is read from config('services.backoffice_origin'), which maps to
     * the BACKOFFICE_ORIGIN env variable (set in config/services.php). Must be a
     * non-empty, non-wildcard explicit origin (e.g. https://backoffice.example.com).
     */
    private function buildCsp(): string
    {
        $origin = $this->resolveBackofficeOrigin();

        if ($origin !== null) {
            // Explicit, validated origin — allow it alongside 'self'.
            return implode('; ', [
                "default-src 'self'",
                "frame-ancestors 'self' {$origin}",
                "connect-src 'self' {$origin}",
                "script-src 'self'",
                "style-src 'self'",
                "img-src 'self' data:",
                "font-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
        }

        // Safe block-all default when no valid origin is configured.
        return implode('; ', [
            "default-src 'self'",
            "frame-ancestors 'none'",
            "connect-src 'none'",
            "script-src 'none'",
            "style-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
        ]);
    }

    /**
     * Resolve and validate the backoffice origin from the config.
     *
     * Returns the origin string if it is valid (non-empty, non-wildcard, starts with https?://).
     * Returns null and logs a warning if the value is absent or invalid.
     */
    private function resolveBackofficeOrigin(): ?string
    {
        // Delegated to App\Support\Http\BackofficeOrigin (self-service-password-reset
        // AD-5): the password-reset link builder needs the SAME rules — non-empty,
        // non-wildcard, explicit http(s):// origin — and two copies of them would
        // eventually disagree, shipping a working CSP with broken emails or the
        // reverse. The safe default on null stays HERE (block-all CSP); the
        // resolver decides validity, never what to do about it.
        return BackofficeOrigin::resolve('SecurityHeaders');
    }
}
