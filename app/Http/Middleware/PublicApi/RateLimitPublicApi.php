<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Support\PublicApi\Problem;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Per-organization, per-mode rate limiting for the BEAI Public API (`/v1`)
 * — SPEC.md §3.2 "Rate limiting".
 *
 * MUST run AFTER `AuthenticatePublicApi` (needs `public_api.client`) — order
 * relative to `PublicApiTenantContext` does not matter for THIS class (it
 * reads the client directly, not `TenantResolver`/`ApiMode`), but
 * `routes/api.php` places it after both, last before `SubstituteBindings`,
 * so a request that is going to be rejected for tenancy reasons never
 * consumes a bucket slot.
 *
 * Backed by the framework's own `Illuminate\Cache\RateLimiter` (a thin
 * FIXED-WINDOW counter over the configured cache store — Redis in
 * production, `array` in tests) rather than Laravel's `ThrottleRequests`
 * middleware/`throttle:` alias: that middleware hardcodes its own header
 * names (`X-RateLimit-*`, and an ABSOLUTE-timestamp `X-RateLimit-Reset`),
 * which do not match this contract's `RateLimit-Limit`/`RateLimit-Remaining`/
 * `RateLimit-Reset` (seconds-until-reset) headers — see
 * `withHeaders()`/`rateLimitedResponse()` below, which build the contract's
 * exact shape instead.
 *
 * G-30 (documented judgement call, review follow-up 5): SPEC.md §3.2 itself
 * says "token bucket", but this implementation — and every implementation
 * built on `Illuminate\Cache\RateLimiter` — is a FIXED-WINDOW counter, not a
 * token bucket. The two are NOT interchangeable: a token bucket refills
 * smoothly and bounds the rate everywhere on the timeline, while a fixed
 * window resets its counter at a hard boundary, so a client that sends
 * `max` requests at the very end of one window and `max` more at the very
 * start of the next can push up to `2 * max` requests through in a short
 * span straddling the boundary. That cross-window burst is an ACCEPTED,
 * documented deviation from the spec's literal wording — re-implementing a
 * true token bucket is out of scope for this deviation — and is distinct
 * from the intra-window race fixed below (a request must never exceed
 * `max` WITHIN a single window, burst or not).
 *
 * The bucket definition itself lives in exactly ONE place —
 * `AppServiceProvider::boot()`'s named `RateLimiter::for('public-api', …)`
 * registration, which this middleware resolves through `$limiter->limiter(
 * 'public-api')` rather than calling `keyFor()`/`maxAttemptsFor()`
 * directly, so the named limiter is the bucket's single source of truth in
 * practice, not a second, parallel definition that happens to compute the
 * same numbers today (review follow-up 5). `keyFor()`/`maxAttemptsFor()`
 * stay `public static` — `AppServiceProvider`'s own closure calls them, and
 * they remain independently unit-testable.
 *
 * Bucket key: `public-api:{organization_id}:{mode}` — SPEC.md §3.2 "per
 * organization" plus this API's own test/live isolation (SPEC.md §3.7): a
 * test-mode integration burning through its bucket must never throttle that
 * SAME organization's live traffic, and vice versa.
 *
 * Every cache touch in this class (the bucket counter AND the per-org
 * override lookup) is exception-guarded and FAILS OPEN on a cache outage
 * (review follow-up 2), the same discipline `App\Support\PublicApi\
 * ApiKeyResolver` already applies to its own denylist/legacy-rows checks: a
 * Redis outage must never turn every authenticated `/v1` request into a
 * 500. A failed-open request carries no `RateLimit-*` headers (there is no
 * honest count to report) rather than a fabricated "full limit remaining"
 * value.
 */
final class RateLimitPublicApi
{
    /**
     * The fixed window Laravel's `RateLimiter::hit()` decays over — "per
     * minute" per SPEC.md §3.2's own units ("600 req/min").
     */
    private const WINDOW_SECONDS = 60;

    /**
     * How long a resolved org override pair (`public_api_rate_limit_live`/
     * `_test`) is cached before being re-read from `organizations` — review
     * follow-up 4: `maxAttemptsFor()` was issuing one `organizations` query
     * PER REQUEST (`$client->organization()->first()`) purely to read two
     * columns that change, at most, as often as an admin edits them in the
     * backoffice. 60s mirrors `ApiKeyResolver::LEGACY_ROWS_CACHE_TTL_SECONDS`'s
     * own "bounded staleness is fine, an admin does not expect the new
     * limit to apply mid-request" reasoning.
     */
    private const ORG_OVERRIDE_TTL_SECONDS = 60;

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

        [$key, $max] = $this->resolveBucket($request, $client);

        // Review follow-up 3: hit-THEN-compare, never check-then-hit. The
        // old `tooManyAttempts($key, $max)` pre-check and the `hit()` that
        // followed it were two SEPARATE cache round-trips, so two
        // concurrent requests could both observe "under the limit" before
        // either had incremented — a classic check-then-act race that lets
        // a burst exceed `max` within one window. `hit()` alone is a single
        // atomic increment on the underlying store (Redis `INCR`); trusting
        // ITS return value — never a later, independent `attempts()` read —
        // is what makes the gate decision atomic with the write.
        try {
            $hits = $this->limiter->hit($key, self::WINDOW_SECONDS);
        } catch (Throwable $e) {
            self::logCacheOutage($e);

            return $next($request);
        }

        if ($hits > $max) {
            return $this->rateLimitedResponse($request, $key, $max);
        }

        return $this->withHeaders($next($request), $key, $max, $hits);
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
     * per org in backoffice)"). Review follow-up 4: reads the two override
     * columns through `organizationOverrides()`'s 60s cache rather than a
     * fresh `organizations` query every call.
     */
    public static function maxAttemptsFor(ApiClient $client): int
    {
        $overrides = self::organizationOverrides($client->organization_id);

        return match ($client->mode) {
            ApiKeyMode::Live => $overrides['live'] ?? config()->integer('public_api.rate_limit.live', 600),
            ApiKeyMode::Test => $overrides['test'] ?? config()->integer('public_api.rate_limit.test', 120),
        };
    }

    /**
     * Resolves the bucket key/limit through the named `public-api` limiter
     * `AppServiceProvider::boot()` registers (review follow-up 5), falling
     * back to calling `keyFor()`/`maxAttemptsFor()` directly only if that
     * registration is somehow missing — defensive, not the expected path.
     *
     * @return array{0: string, 1: int}
     */
    private function resolveBucket(Request $request, ApiClient $client): array
    {
        $resolver = $this->limiter->limiter('public-api');
        $resolved = is_callable($resolver) ? $resolver($request) : null;
        $limit = $resolved instanceof Limit ? $resolved : null;

        if ($limit !== null && is_string($limit->key) && $limit->key !== '') {
            return [$limit->key, $limit->maxAttempts];
        }

        return [self::keyFor($client), self::maxAttemptsFor($client)];
    }

    /**
     * @return array{live: int|null, test: int|null}
     */
    private static function organizationOverrides(int $organizationId): array
    {
        try {
            /** @var array{live: int|null, test: int|null} $overrides */
            $overrides = Cache::remember(
                'public-api:org-rate-limit-overrides:'.$organizationId,
                self::ORG_OVERRIDE_TTL_SECONDS,
                function () use ($organizationId): array {
                    $organization = Organization::query()->find($organizationId);

                    return [
                        'live' => $organization?->public_api_rate_limit_live,
                        'test' => $organization?->public_api_rate_limit_test,
                    ];
                },
            );

            return $overrides;
        } catch (Throwable $e) {
            self::logCacheOutage($e);

            // Fail open to the platform defaults — maxAttemptsFor() applies
            // `?? config(...)` on top of these null values.
            return ['live' => null, 'test' => null];
        }
    }

    private function rateLimitedResponse(Request $request, string $key, int $max): JsonResponse
    {
        try {
            $retryAfter = $this->limiter->availableIn($key);
        } catch (Throwable $e) {
            self::logCacheOutage($e);

            $retryAfter = self::WINDOW_SECONDS;
        }

        return Problem::make($request, 429, 'rate_limited', 'Rate limited', extraHeaders: [
            'Retry-After' => (string) $retryAfter,
            'RateLimit-Limit' => (string) $max,
            'RateLimit-Remaining' => '0',
            'RateLimit-Reset' => (string) $retryAfter,
        ]);
    }

    /**
     * Step 3 Part A follow-up 2 (review follow-up 1 superseded): `$hits` is
     * the exact value `handle()`'s own `hit()` call already returned for
     * THIS request — the same atomic increment that gated the 429 decision
     * above — never a separate `attempts()` read taken afterward. A second,
     * independent cache round trip can observe a DIFFERENT count than the
     * one `hit()` just returned (another request's concurrent `hit()`
     * landing in between), so deriving `RateLimit-Remaining` from anything
     * other than `hit()`'s own return value can under- or over-report how
     * much of the bucket this exact request actually consumed. `hit()`
     * itself already normalises to `int` internally
     * (`Illuminate\Cache\RateLimiter::hit()` casts its own `increment()`
     * read), so the numeric-string-from-Redis concern the old `attempts()`
     * read guarded against does not apply to `$hits`.
     */
    private function withHeaders(Response $response, string $key, int $max, int $hits): Response
    {
        $remaining = max(0, $max - $hits);

        try {
            $reset = $this->limiter->availableIn($key);
        } catch (Throwable $e) {
            self::logCacheOutage($e);

            // Fail open — the response itself is already correct (the
            // request was allowed); omit the RateLimit-* headers rather
            // than report a fabricated count.
            return $response;
        }

        $response->headers->set('RateLimit-Limit', (string) $max);
        $response->headers->set('RateLimit-Remaining', (string) $remaining);
        $response->headers->set('RateLimit-Reset', (string) $reset);

        return $response;
    }

    /**
     * Logged at most once per request — every catch site above is reached
     * at most once per request lifecycle (none of them loop), so no
     * separate de-duplication bookkeeping is needed.
     */
    private static function logCacheOutage(Throwable $e): void
    {
        Log::warning('public-api: rate limiter cache unavailable — failing open', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
