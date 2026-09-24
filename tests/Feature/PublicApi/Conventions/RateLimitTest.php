<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) rate limiting (public-api step 3) — SPEC.md §3.2
 * "Rate limiting".
 *
 * T-CONV-006: RateLimit-* headers present on success, counting down.
 * T-CONV-007: 429 + Retry-After at the limit; per-org isolation; test mode
 * uses the test bucket independently of live; org override column
 * respected.
 */

use App\Enums\ApiKeyMode;
use App\Http\Middleware\PublicApi\AssignRequestId;
use App\Http\Middleware\PublicApi\AuthenticatePublicApi;
use App\Http\Middleware\PublicApi\PublicApiTenantContext;
use App\Http\Middleware\PublicApi\RateLimitPublicApi;
use App\Http\Middleware\PublicApi\RejectApiKeyInQuery;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Helpers\PublicApi\StaleReadArrayStore;
use Tests\Helpers\PublicApi\ThrowingArrayStore;

beforeEach(function (): void {
    Route::middleware([
        AssignRequestId::class,
        RejectApiKeyInQuery::class,
        AuthenticatePublicApi::class,
        PublicApiTenantContext::class,
        RateLimitPublicApi::class,
    ])->prefix('api/v1')->group(function (): void {
        Route::get('/_probe/rl', fn () => response()->json(['ok' => true]));
    });
});

test('T-CONV-006: RateLimit-* headers are present on success and RateLimit-Remaining counts down', function (): void {
    $org = Organization::factory()->create(['public_api_rate_limit_live' => 5]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $r1 = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl');
    $r1->assertOk()
        ->assertHeader('RateLimit-Limit', '5')
        ->assertHeader('RateLimit-Remaining', '4');
    expect((int) $r1->headers->get('RateLimit-Reset'))->toBeGreaterThanOrEqual(0);

    $r2 = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl');
    $r2->assertOk()->assertHeader('RateLimit-Remaining', '3');
});

test('T-CONV-007: exceeding the limit → 429 rate_limited + Retry-After, problem+json valid', function (): void {
    $org = Organization::factory()->create(['public_api_rate_limit_live' => 1]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl')->assertOk();

    $blocked = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl');

    $blocked->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    expect($blocked->headers->get('Retry-After'))->not->toBeNull();
    $this->assertProblemMatchesContract($blocked, 429);
});

test('T-CONV-007: rate limiting is isolated per organization', function (): void {
    $orgA = Organization::factory()->create(['public_api_rate_limit_live' => 1]);
    $keyA = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($keyA)->create(['organization_id' => $orgA->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$keyA])->getJson('/api/v1/_probe/rl')->assertOk();
    $this->withHeaders(['Authorization' => 'Bearer '.$keyA])->getJson('/api/v1/_probe/rl')->assertStatus(429);

    $orgB = Organization::factory()->create(['public_api_rate_limit_live' => 1]);
    $keyB = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($keyB)->create(['organization_id' => $orgB->id]);

    // Org B's bucket is untouched by org A exhausting its own.
    $this->withHeaders(['Authorization' => 'Bearer '.$keyB])->getJson('/api/v1/_probe/rl')->assertOk();
});

test('T-CONV-007: test mode uses the org test-mode limit, independently of an exhausted live bucket, and the org override is respected', function (): void {
    $org = Organization::factory()->create([
        'public_api_rate_limit_live' => 1,
        'public_api_rate_limit_test' => 3,
    ]);

    $liveKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($liveKey)->create(['organization_id' => $org->id, 'mode' => 'live']);

    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->create(['organization_id' => $org->id, 'mode' => 'test']);

    // Exhaust the live bucket (limit 1).
    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])->getJson('/api/v1/_probe/rl')->assertOk();
    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])->getJson('/api/v1/_probe/rl')->assertStatus(429);

    // The SAME organization's test-mode key is unaffected, and reports the
    // org's own test-mode override (3), not the live limit (1) or the
    // platform default (120).
    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])->getJson('/api/v1/_probe/rl')
        ->assertOk()
        ->assertHeader('RateLimit-Limit', '3');
});

test('a request with no resolved public_api.client → 401 invalid_api_key (defensive branch)', function (): void {
    Route::middleware([RateLimitPublicApi::class])->prefix('api/v1')->group(function (): void {
        Route::get('/_probe/rl-unauth', fn () => response()->json(['ok' => true]));
    });

    $response = $this->getJson('/api/v1/_probe/rl-unauth');

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_api_key');
});

test('T-CONV-007: with no org override, the platform default limit applies', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl')
        ->assertOk()
        ->assertHeader('RateLimit-Limit', (string) config('public_api.rate_limit.live'));
});

// ─── Step 3 review follow-ups (items 1-4) ──────────────────────────────────

test('review follow-up 1: RateLimit-Remaining is computed correctly when the cache store returns attempts as a numeric string (Redis behaviour)', function (): void {
    $org = Organization::factory()->create(['public_api_rate_limit_live' => 10]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    // A REAL cache repository underneath (hit()/availableIn() must keep
    // working) whose attempts() is forced to return a numeric STRING —
    // exactly what Illuminate\Cache\RateLimiter::attempts() gets back from
    // a Redis-backed store, since increment()/add() are called with
    // withoutSerializationOrCompression() and Redis itself has no native
    // integer type.
    $fakeLimiter = new class(app('cache.store')) extends RateLimiter
    {
        public function attempts($key)
        {
            return '3';
        }
    };
    app()->instance(RateLimiter::class, $fakeLimiter);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl');

    $response->assertOk()->assertHeader('RateLimit-Remaining', '7');
});

test('review follow-up 2: a rate-limiter cache outage fails open — the request proceeds without RateLimit-* headers, never a 500', function (): void {
    $org = Organization::factory()->create(['public_api_rate_limit_live' => 5]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $throwingRepository = new Repository(new ThrowingArrayStore(['increment']));
    app()->instance(RateLimiter::class, new RateLimiter($throwingRepository));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl');

    $response->assertOk();
    expect($response->headers->has('RateLimit-Limit'))->toBeFalse();
    expect($response->headers->has('RateLimit-Remaining'))->toBeFalse();
});

test('review follow-up 3: rate limiting stays atomic — a stale attempts() read never lets a burst exceed the limit', function (): void {
    $org = Organization::factory()->create(['public_api_rate_limit_live' => 3]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    // Every attempts()/tooManyAttempts() read sees "0 attempts so far",
    // frozen — exactly what a concurrent request's own hit() has not yet
    // been observed as under a real check-then-hit race. increment()
    // (what hit() writes through) still mutates the real counter, so only
    // an implementation that trusts hit()'s OWN return value — never a
    // separate attempts() read — stays correctly bounded.
    $staleRepository = new Repository(new StaleReadArrayStore(0));
    app()->instance(RateLimiter::class, new RateLimiter($staleRepository));

    $statuses = [];
    foreach (range(1, 4) as $_) {
        $statuses[] = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
            ->getJson('/api/v1/_probe/rl')
            ->getStatusCode();
    }

    expect($statuses)->toBe([200, 200, 200, 429]);
});

test('review follow-up 3b: N+1 sequential hits at the limit boundary yield exactly N successes', function (): void {
    $org = Organization::factory()->create(['public_api_rate_limit_live' => 4]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    $statuses = [];
    foreach (range(1, 5) as $_) {
        $statuses[] = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
            ->getJson('/api/v1/_probe/rl')
            ->getStatusCode();
    }

    expect($statuses)->toBe([200, 200, 200, 200, 429]);
});

test('review follow-up 4: the organization rate-limit override is cached — the second request makes zero organization queries', function (): void {
    $org = Organization::factory()->create(['public_api_rate_limit_live' => 7]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id]);

    // Warm-up — populate whatever cache the fix introduces, so only the
    // SECOND request is charged for it.
    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl')->assertOk();

    $organizationQueries = 0;
    DB::listen(function ($query) use (&$organizationQueries): void {
        if (str_contains($query->sql, '"organizations"')) {
            $organizationQueries++;
        }
    });

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/_probe/rl')
        ->assertOk()
        ->assertHeader('RateLimit-Limit', '7');

    expect($organizationQueries)->toBe(0);
});
