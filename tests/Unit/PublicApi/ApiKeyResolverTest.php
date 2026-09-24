<?php

declare(strict_types=1);

/**
 * ApiKeyResolver unit tests (public-api step 3, Part A finding 4 review
 * follow-up).
 *
 * Covers the two previously-untested branches of `legacyRowsExist()` /
 * `lookup()`:
 *   - an unknown `beai_test_` key never runs the legacy hash-only fallback
 *     query at all (it returns null right after the prefix-candidate miss —
 *     see `ApiKeyResolver::lookup()`'s own docblock, "a beai_test_ key
 *     NEVER takes this path").
 *   - a cache failure on the `legacyRowsExist()` check fails OPEN (runs the
 *     fallback anyway) rather than silently refusing a legitimate legacy key.
 *
 * Plus the new `ApiClient::booted()` `created` hook: creating a client
 * invalidates a previously-cached negative answer immediately, rather than
 * waiting out the TTL.
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\ApiKeyResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

test('an unknown beai_test_ key resolves to null without running the legacy hash-only fallback query', function (): void {
    $org = Organization::factory()->create();
    // A real live client exists, so the table is non-empty — proves the
    // fallback is skipped because of the Test-mode short-circuit, not
    // because the table happened to be empty.
    ApiClient::factory()->create(['organization_id' => $org->id]);

    $unknownTestKey = ApiKeyGenerator::generate(ApiKeyMode::Test);

    DB::enableQueryLog();
    $result = ApiKeyResolver::resolve($unknownTestKey, allowTestMode: true);
    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    expect($result)->toBeNull();

    $fallbackQueries = collect($queryLog)->filter(
        fn (array $entry): bool => str_contains($entry['query'], 'key_hash')
            && ! str_contains($entry['query'], 'key_prefix'),
    );

    expect($fallbackQueries)->toHaveCount(0);
});

test('a legacyRowsExist() cache failure fails open and the legacy fallback still resolves a pre-migration row', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);

    $client = ApiClient::factory()->withRawKey($rawKey)->preMigrationRow()->create([
        'organization_id' => $org->id,
    ]);

    expect($client->key_prefix)->toBeNull();

    // Cache::partialMock() does not forward unstubbed calls to the SAME
    // underlying array-store instance, so Cache::has() (the denylist check
    // `resolve()` runs after lookup()) fails too — a full cache outage, not
    // only legacyRowsExist()'s own `remember()` call. `resolve()` already
    // documents exactly this cascade: a `Cache::has()` failure re-runs
    // `lookup()` as "a FRESH DB active() re-query", which calls
    // `legacyRowsExist()` (and therefore `remember()`) a second time — so
    // TWO calls, both throwing, is the correct shape for this scenario, not
    // a mock artifact.
    Cache::partialMock()
        ->shouldReceive('remember')
        ->twice()
        ->andThrow(new RuntimeException('cache store unavailable'));

    $resolved = ApiKeyResolver::resolve($rawKey, allowTestMode: false);

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($client->id);
});

test('creating an ApiClient invalidates a previously-cached negative legacyRowsExist() answer immediately', function (): void {
    $org = Organization::factory()->create();

    // Prime the cache as "no legacy rows exist" (true resolution path,
    // false answer) via a malformed key that forces the legacyRowsExist()
    // check with an empty table.
    $primingResult = ApiKeyResolver::resolve('not-a-beai-key-at-all', allowTestMode: false);
    expect($primingResult)->toBeNull();

    // Now create a legacy-shaped row (key_prefix null) — the created hook
    // must forget the cached "false" immediately, not after the TTL.
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    $client = ApiClient::factory()->withRawKey($rawKey)->preMigrationRow()->create([
        'organization_id' => $org->id,
    ]);

    $resolved = ApiKeyResolver::resolve($rawKey, allowTestMode: false);

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($client->id);
});
