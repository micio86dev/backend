<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Support\PublicApi\Problem;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-organization, per-mode token-bucket rate limiting for the BEAI Public
 * API (`/v1`) — SPEC.md §3.2 "Rate limiting".
 *
 * MUST run AFTER `AuthenticatePublicApi` (needs `public_api.client`) — order
 * relative to `PublicApiTenantContext` does not matter for THIS class (it
 * reads the client directly, not `TenantResolver`/`ApiMode`), but
 * `routes/api.php` places it after both, last before `SubstituteBindings`,
 * so a request that is going to be rejected for tenancy reasons never
 * consumes a bucket slot.
 *
 * Backed by the framework's own `Illuminate\Cache\RateLimiter` (a thin
 * fixed-window counter over the configured cache store — Redis in
 * production, `array` in tests) rather than Laravel's `ThrottleRequests`
 * middleware/`throttle:` alias: that middleware hardcodes its own header
 * names (`X-RateLimit-*`, and an ABSOLUTE-timestamp `X-RateLimit-Reset`),
 * which do not match this contract's `RateLimit-Limit`/`RateLimit-Remaining`/
 * `RateLimit-Reset` (seconds-until-reset) headers — see
 * `withHeaders()`/`rateLimitedResponse()` below, which build the contract's
 * exact shape instead. `AppServiceProvider::boot()` still registers a named
 * `RateLimiter::for('public-api', …)` limiter using this class's own
 * `keyFor()`/`maxAttemptsFor()`, so the bucket definition has exactly one
 * source even though this middleware talks to the underlying counter
 * directly rather than through the `throttle:` middleware indirection.
 *
 * Bucket key: `public-api:{organization_id}:{mode}` — SPEC.md §3.2 "per
 * organization" plus this API's own test/live isolation (SPEC.md §3.7): a
 * test-mode integration burning through its bucket must never throttle that
 * SAME organization's live traffic, and vice versa.
 */
final class RateLimitPublicApi
{
    /**
     * The fixed window Laravel's `RateLimiter::hit()` decays over — "per
     * minute" per SPEC.md §3.2's own units ("600 req/min").
     */
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiClient|null $client */
        $client = $request->attributes->get('public_api.client');

        if (! $client instanceof ApiClient) {
            // Unreachable through the real /v1 stack — AuthenticatePublicApi
            // always runs first and already 401s a missing/invalid key — but
            // checked explicitly rather than trusted, same discipline as
            // PublicApiTenantContext's own null-client guard.
            return Problem::make(
                $request, 401, 'invalid_api_key', 'Invalid API key',
                extraHeaders: ['WWW-Authenticate' => 'Bearer'],
            );
        }

        $key = self::keyFor($client);
        $max = self::maxAttemptsFor($client);

        if ($this->limiter->tooManyAttempts($key, $max)) {
            return $this->rateLimitedResponse($request, $key, $max);
        }

        $this->limiter->hit($key, self::WINDOW_SECONDS);

        return $this->withHeaders($next($request), $key, $max);
    }

    /**
     * The bucket key — public so `AppServiceProvider`'s named-limiter
     * registration derives the SAME key rather than a second, independently
     * maintained copy.
     */
    public static function keyFor(ApiClient $client): string
    {
        return "public-api:{$client->organization_id}:{$client->mode->value}";
    }

    /**
     * The max requests/window for this client's organization and mode —
     * the org's own override when set, else the platform default (SPEC.md
     * §3.2 "Defaults `live` 600 req/min, `test` 120 req/min (configurable
     * per org in backoffice)").
     */
    public static function maxAttemptsFor(ApiClient $client): int
    {
        // $client->organization_id is NOT NULL with a foreign key constraint
        // to organizations(id), so first() is never null here — Larastan
        // infers this precisely (see api/CLAUDE.md's own Larastan note on a
        // NOT NULL-backed BelongsTo relation).
        $organization = $client->organization()->first();

        return match ($client->mode) {
            ApiKeyMode::Live => $organization->public_api_rate_limit_live
                ?? config()->integer('public_api.rate_limit.live', 600),
            ApiKeyMode::Test => $organization->public_api_rate_limit_test
                ?? config()->integer('public_api.rate_limit.test', 120),
        };
    }

    private function rateLimitedResponse(Request $request, string $key, int $max): JsonResponse
    {
        $retryAfter = $this->limiter->availableIn($key);

        return Problem::make($request, 429, 'rate_limited', 'Rate limited', extraHeaders: [
            'Retry-After' => (string) $retryAfter,
            'RateLimit-Limit' => (string) $max,
            'RateLimit-Remaining' => '0',
            'RateLimit-Reset' => (string) $retryAfter,
        ]);
    }

    private function withHeaders(Response $response, string $key, int $max): Response
    {
        // RateLimiter::attempts() is genuinely declared @return mixed
        // upstream (it proxies whatever the cache store's get() returns) —
        // narrowed explicitly rather than cast, since a cache-driver quirk
        // returning something else must fall back to 0, not silently become
        // a bogus int via a raw cast.
        $attempts = $this->limiter->attempts($key);
        $attempts = is_int($attempts) ? $attempts : 0;

        $remaining = max(0, $max - $attempts);

        $response->headers->set('RateLimit-Limit', (string) $max);
        $response->headers->set('RateLimit-Remaining', (string) $remaining);
        $response->headers->set('RateLimit-Reset', (string) $this->limiter->availableIn($key));

        return $response;
    }
}
