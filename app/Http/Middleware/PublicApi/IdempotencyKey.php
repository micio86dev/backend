<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use App\Models\ApiClient;
use App\Support\PublicApi\Problem;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

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
        $lock = Cache::lock('idem:'.$scope, config()->integer('public_api.idempotency.lock_ttl_seconds', 30));

        try {
            $lock->block(config()->integer('public_api.idempotency.lock_wait_seconds', 5));
        } catch (LockTimeoutException) {
            return Problem::make($request, 409, 'idempotency_in_progress', 'Idempotency key already in progress');
        }

        try {
            return $this->handleLocked($request, $next, $scope);
        } finally {
            $lock->release();
        }
    }

    /**
     * The scope key `hash('sha256', "{org_id}|{mode}|{method}|{path}|{key}")`
     * — SPEC.md §3.2 "same key + same org + same body within 24h". Public so
     * a test can hold the SAME lock externally to exercise the concurrent
     * (`idempotency_in_progress`) branch deterministically.
     */
    public static function scopeFor(ApiClient $client, Request $request, string $rawKey): string
    {
        return hash('sha256', implode('|', [
            $client->organization_id,
            $client->mode->value,
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

        /** @var array{fingerprint: string, status: int, headers: array<string, string>, body: string}|null $cached */
        $cached = Cache::get($storeKey);

        if ($cached !== null) {
            if (! hash_equals($cached['fingerprint'], $fingerprint)) {
                return Problem::make($request, 409, 'idempotency_key_reused', 'Idempotency key reused with a different request body');
            }

            return self::replay($cached);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($storeKey, [
                'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(),
                'headers' => self::replayableHeaders($response),
                'body' => (string) $response->getContent(),
            ], config()->integer('public_api.idempotency.record_ttl_seconds', 86400));
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
}
