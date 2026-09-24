<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use App\Models\ApiClient;
use App\Support\PublicApi\Problem;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `Idempotency-Key` support for `POST` routes on the BEAI Public API
 * (`/v1`) — SPEC.md §3.2 "Idempotency", registered as the `idempotent` alias
 * (public-api step 3) for routes to opt into explicitly (`->middleware(
 * 'idempotent')`) once step 4+ adds the first `POST` endpoint — no route
 * applies it yet.
 *
 * Storage is G-26 (documented judgement call): the CONFIGURED cache store —
 * Redis in production, `array` in tests — the same store every other
 * `/v1` cache touch in this codebase already uses
 * (`App\Support\PublicApi\ApiKeyResolver`'s denylist/legacy-rows checks,
 * `RateLimitPublicApi`'s buckets). No dedicated idempotency store exists
 * (no separate table, no separate Redis logical DB): a replay record is
 * disposable cache state by nature (it exists ONLY to answer "have I seen
 * this key+body before, in the last 24h" — see `record_ttl_seconds`), never
 * a durable audit trail, so it belongs with the rest of this API's
 * request-scoped cache state rather than acquiring its own persistence
 * layer.
 *
 * Every cache touch below — the lock, the replay-record read, and the
 * replay-record write — is exception-guarded and FAILS OPEN on a cache
 * outage (step 3 review follow-up 7), the same discipline
 * `App\Http\Middleware\PublicApi\RateLimitPublicApi` and `App\Support\
 * PublicApi\ApiKeyResolver` already apply to their own cache touches: a
 * Redis outage must never turn a `POST` request into a 500, and a cache
 * failure that happens AFTER the handler already succeeded must never
 * discard that response — idempotency itself is a best-effort convenience,
 * not something the caller's request should die for.
 *
 * MUST run after `AuthenticatePublicApi` (needs `public_api.client` for the
 * scope key) — applied per-route via the `idempotent` alias rather than
 * fixed into the `/v1` group order in `routes/api.php`, because only some
 * `POST` endpoints in the contract document `idempotencyKey` as an accepted
 * parameter.
 */
final class IdempotencyKey
{
    private const MAX_KEY_LENGTH = 255;

    private const HEADER = 'Idempotency-Key';

    /**
     * Response headers replayed alongside a cached body — a fixed,
     * deliberately small allow-list (not every header the original response
     * carried), since blindly replaying e.g. `Set-Cookie` or a
     * request-specific `X-Request-Id` on a LATER request would be wrong.
     *
     * @var list<string>
     */
    private const REPLAYED_HEADERS = ['Content-Type', 'Location'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $request->header(self::HEADER);

        if ($rawKey === null || $rawKey === '') {
            return $next($request);
        }

        if (strlen($rawKey) > self::MAX_KEY_LENGTH) {
            return Problem::make($request, 422, 'validation_failed', 'Validation failed', errors: [
                ['field' => self::HEADER, 'code' => 'max'],
            ]);
        }

        /** @var ApiClient|null $client */
        $client = $request->attributes->get('public_api.client');

        if (! $client instanceof ApiClient) {
            return Problem::make(
                $request, 401, 'invalid_api_key', 'Invalid API key',
                extraHeaders: ['WWW-Authenticate' => 'Bearer'],
            );
        }

        $scope = self::scopeFor($client, $request, $rawKey);

        try {
            $lock = Cache::lock('idem:'.$scope, config()->integer('public_api.idempotency.lock_ttl_seconds', 30));
            $lock->block(config()->integer('public_api.idempotency.lock_wait_seconds', 5));
        } catch (LockTimeoutException) {
            return Problem::make($request, 409, 'idempotency_in_progress', 'Idempotency key already in progress');
        } catch (Throwable $e) {
            self::logCacheOutage($e);

            // Fail open — process the request without idempotency rather
            // than 500 the caller for a cache outage that has nothing to do
            // with their request.
            return $next($request);
        }

        try {
            return $this->handleLocked($request, $next, $scope);
        } finally {
            self::releaseQuietly($lock);
        }
    }

    /**
     * The scope key
     * `hash('sha256', "{org_id}|{mode}|{client_id}|{method}|{path}|{key}")`
     * — SPEC.md §3.2 "same key + same org + same body within 24h", WITH the
     * API client id folded in (step 3 review follow-up 6): two DIFFERENT
     * clients of the SAME organization sharing an `Idempotency-Key` value —
     * a coincidence the contract never rules out — used to collide on the
     * exact same scope, so the second client's request silently replayed
     * the FIRST client's response instead of running its own. Public so a
     * test can hold the SAME lock externally to exercise the concurrent
     * (`idempotency_in_progress`) branch deterministically.
     */
    public static function scopeFor(ApiClient $client, Request $request, string $rawKey): string
    {
        return hash('sha256', implode('|', [
            $client->organization_id,
            $client->mode->value,
            $client->id,
            $request->method(),
            $request->path(),
            $rawKey,
        ]));
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function handleLocked(Request $request, Closure $next, string $scope): Response
    {
        $storeKey = 'idempotency:'.$scope;
        $fingerprint = hash('sha256', (string) $request->getContent());

        try {
            /** @var array{fingerprint: string, status: int, headers: array<string, string>, body: string}|null $cached */
            $cached = Cache::get($storeKey);
        } catch (Throwable $e) {
            self::logCacheOutage($e);

            // Fail open — process the request without idempotency rather
            // than 500 on a read failure.
            $cached = null;
        }

        if ($cached !== null) {
            if (! hash_equals($cached['fingerprint'], $fingerprint)) {
                return Problem::make($request, 409, 'idempotency_key_reused', 'Idempotency key reused with a different request body');
            }

            return self::replay($cached);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            try {
                Cache::put($storeKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'headers' => self::replayableHeaders($response),
                    'body' => (string) $response->getContent(),
                ], config()->integer('public_api.idempotency.record_ttl_seconds', 86400));
            } catch (Throwable $e) {
                self::logCacheOutage($e);

                // Guarded — a cache write failure here must never turn an
                // already-SUCCEEDED request into a 500; the response below
                // is returned normally, it is just never recorded for
                // replay (the caller's own retry, if any, re-executes the
                // handler instead of replaying — no worse than idempotency
                // being unavailable entirely).
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private static function replayableHeaders(Response $response): array
    {
        $headers = [];

        foreach (self::REPLAYED_HEADERS as $name) {
            $value = $response->headers->get($name);

            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param  array{fingerprint: string, status: int, headers: array<string, string>, body: string}  $cached
     */
    private static function replay(array $cached): Response
    {
        $response = new HttpResponse($cached['body'], $cached['status']);

        foreach ($cached['headers'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    /**
     * A lock release failure is non-fatal — the lock's own TTL
     * (`public_api.idempotency.lock_ttl_seconds`) bounds the worst case
     * (another request waits out the TTL instead of the release), never a
     * 500 on an otherwise-successful request.
     */
    private static function releaseQuietly(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $e) {
            self::logCacheOutage($e);
        }
    }

    /**
     * Logged at most once per request — every catch site above is reached
     * at most once per request lifecycle (none of them loop), so no
     * separate de-duplication bookkeeping is needed.
     */
    private static function logCacheOutage(Throwable $e): void
    {
        Log::warning('public-api: idempotency cache unavailable — failing open', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
