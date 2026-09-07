<?php

declare(strict_types=1);

/**
 * ActingOrganization survives the CACHE STORE, not just the array store.
 *
 * The switch was written and tested entirely under `CACHE_STORE=array`
 * (phpunit.xml), where `Cache::get()` hands back the very PHP value that was
 * put in — an `int`. Production runs `CACHE_STORE=redis`, and Laravel's
 * RedisStore does NOT serialize numerics: `shouldBeStoredWithoutSerialization()`
 * returns true for any finite numeric, so the id is written as a bare Redis
 * string, and `unserialize()` returns it untouched because `is_numeric()` is
 * true. phpredis `GET` yields a string, so `Cache::get()` returns `'5'`.
 *
 * `for()` guarded that read with `is_int()`, which a numeric string fails. The
 * switch therefore returned 200 on every PUT and resolved to null on every
 * subsequent request: the superadmin picked a client, the page reloaded, and
 * the selection was gone. Confirmed in production, where the PUT's 200 was
 * immediately followed by `GET /api/organization` → 404 — the response of a
 * superadmin with NO acting organization.
 *
 * The whole suite stayed green throughout, and that is the actual lesson:
 * a value's TYPE here is a property of the store, so a test on one store
 * proves nothing about the other. Both are pinned below.
 */

use App\Support\Tenancy\ActingOrganization;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * A cache store that returns numerics exactly as RedisStore does.
 *
 * Hand-rolled rather than mocked: the point of this test is the round-trip
 * CONTRACT, and a mock returning `'5'` because the test told it to would
 * assert nothing the production store does. This reproduces the two lines of
 * RedisStore that matter — a finite numeric is stored unserialized, and read
 * back as the string the driver hands over.
 */
final class NumericPassthroughStore implements Store
{
    /** @var array<string, string> */
    private array $values = [];

    public function get($key): mixed
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        // RedisStore::unserialize() — a numeric short-circuits and comes back
        // as the string the driver handed over; everything else is unserialized.
        // Both halves matter: without the unserialize, a non-numeric value
        // reached `for()` as a serialized STRING where real Redis hands it the
        // original array, so the null-rejection test passed on a value shape
        // production cannot produce.
        return is_numeric($value) ? $value : unserialize($value);
    }

    /** @return array<string, mixed> */
    public function many(array $keys): array
    {
        return array_combine($keys, array_map(fn (string $key): mixed => $this->get($key), $keys));
    }

    public function put($key, $value, $seconds): bool
    {
        // RedisStore::shouldBeStoredWithoutSerialization() — a finite numeric
        // goes in raw, and Redis has no type but string.
        $this->values[$key] = is_numeric($value) ? (string) $value : serialize($value);

        return true;
    }

    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1): int|bool
    {
        return false;
    }

    public function decrement($key, $value = 1): int|bool
    {
        return false;
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function touch($key, $seconds): bool
    {
        // No TTLs here — ActingOrganization stores forever, deliberately.
        return array_key_exists($key, $this->values);
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}

it('reads back an acting organization the store returns as a numeric string', function (): void {
    Cache::swap(new Repository(new NumericPassthroughStore));

    $acting = new ActingOrganization;
    $acting->set(42, 7);

    expect($acting->for(42))->toBe(7);
});

/**
 * `ctype_digit`, NOT `is_numeric` — and this is the test that says so.
 *
 * The guard's docblock argues at length that `is_numeric` would accept `'1.9'`
 * and truncate it to a DIFFERENT TENANT'S id. That is a cross-tenant claim, and
 * it was made with nothing exercising it: swapping `ctype_digit($value)` for
 * `is_numeric($value)` left the suite green. A guard no mutation can break is
 * not a guard, it is a comment — the same shape as the defect this whole file
 * exists to pin, where every test passed while production read null forever.
 *
 * @param  array{0: mixed, 1: ?int}  $case
 */
it('reads only a positive whole number as a client selection', function (mixed $stored, ?int $expected): void {
    $store = new NumericPassthroughStore;
    // Written straight to the KEY, not through set(): the question is what
    // happens when the key holds something set() would never have put there —
    // a collision, a hand-edited value, a future writer with another shape.
    $store->forever('superadmin:acting-org:42', $stored);
    Cache::swap(new Repository($store));

    expect((new ActingOrganization)->for(42))->toBe($expected);
})->with([
    // The mutation guard. `is_numeric('1.9')` is true and `(int) '1.9'` is 1 —
    // organization 1, which belongs to somebody else.
    'a decimal truncating onto another tenant' => ['1.9', null],
    'a negative id' => ['-3', null],
    // `ctype_digit('0')` is true, so this reached `for()` as organization 0 —
    // an id the sequence never issues.
    'zero, which no organization has' => ['0', null],
    'scientific notation' => ['1e2', null],
    'a leading-plus integer' => ['+7', null],
    'junk' => ['not-an-id', null],
    // Reaches `for()` as a real array, because the fake store unserializes
    // exactly where RedisStore does.
    'a structure' => [['nonsense'], null],
    // And the values that ARE a selection still are.
    'a digit string, as redis returns it' => ['7', 7],
    'a native int, as the array store returns it' => [7, 7],
]);

it('round-trips through the REAL redis store, which is what production runs', function (): void {
    // Gated exactly like tests/Feature/Queue/RealWorkerRedisDriverTest.php:
    // ci.yml's main `test` job installs ext-redis but provisions no redis
    // service, so checking the extension alone turns an honest skip into a
    // suite failure. CI Tier 2 and local docker-compose both run this for real.
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis not installed on this host PHP runtime.');
    }

    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('No redis server reachable: '.$e->getMessage());
    }

    config()->set('cache.default', 'redis');
    Cache::purge('redis');

    $acting = new ActingOrganization;

    try {
        $acting->set(4242, 7);

        expect($acting->for(4242))->toBe(7);
    } finally {
        $acting->forget(4242);
    }
});
