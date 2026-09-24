<?php

declare(strict_types=1);

namespace Tests\Helpers\PublicApi;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * An in-memory cache store that throws on selected operations — simulates a
 * Redis outage for the BEAI Public API (`/v1`) cache-outage fail-open tests
 * (public-api step 3 review follow-ups, items 2/7): `RateLimitPublicApi` and
 * `IdempotencyKey` MUST fail open (process the request without their cache
 * touch) rather than 500 when the configured cache store is unreachable.
 *
 * Every other operation delegates to the real `ArrayStore` behaviour, so a
 * test only needs to name the specific method(s) it wants to fail.
 *
 * `$keyPrefix`, when given, narrows failures to keys starting with it —
 * needed whenever the SAME swapped-in store also backs unrelated
 * application code sharing the default cache facade (e.g. a probe route's
 * own `Cache::increment()` business logic), so only the middleware's own
 * cache touch is simulated as down, not every cache touch in the request.
 */
final class ThrowingArrayStore extends ArrayStore
{
    /**
     * @param  list<string>  $throwingMethods  the `Store`/`LockProvider` method names that should throw.
     */
    public function __construct(
        private readonly array $throwingMethods,
        private readonly ?string $keyPrefix = null,
    ) {
        parent::__construct();
    }

    public function get($key)
    {
        $this->throwIfConfigured(__FUNCTION__, (string) $key);

        return parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        $this->throwIfConfigured(__FUNCTION__, (string) $key);

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $this->throwIfConfigured(__FUNCTION__, (string) $key);

        return parent::increment($key, $value);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->throwIfConfigured(__FUNCTION__, (string) $name);

        return parent::lock($name, $seconds, $owner);
    }

    private function throwIfConfigured(string $method, string $key): void
    {
        if (! in_array($method, $this->throwingMethods, true)) {
            return;
        }

        if ($this->keyPrefix !== null && ! str_starts_with($key, $this->keyPrefix)) {
            return;
        }

        throw new RuntimeException("ThrowingArrayStore: simulated outage on {$method}({$key})");
    }
}
