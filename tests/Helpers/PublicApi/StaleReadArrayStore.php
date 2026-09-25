<?php

declare(strict_types=1);

namespace Tests\Helpers\PublicApi;

use Illuminate\Cache\ArrayStore;

/**
 * Simulates the read side of the `RateLimitPublicApi` check-then-hit race
 * (public-api step 3 review follow-up, item 3): `get()` on a rate-limit
 * counter key — what `RateLimiter::attempts()`/`tooManyAttempts()` reads —
 * always answers with a FROZEN value, exactly like a concurrent request's
 * own `hit()` a real Redis-backed counter has already applied but this
 * request's own `attempts()` read has not yet observed. `increment()` —
 * what `RateLimiter::hit()` writes through — keeps its own REAL running
 * count in `$realCounts`, entirely independent of the frozen `get()`
 * answer, so a fix that trusts `hit()`'s OWN return value rather than a
 * separate `attempts()` read stays correctly bounded even though every
 * counter `get()` in this test lies. Timer keys (`{key}:timer`, which
 * `availableIn()`/`increment()` also touch) are left to the real `ArrayStore`
 * behaviour — only the counter read is frozen.
 *
 * A single-process, sequential Pest test cannot reproduce genuine thread
 * interleaving; this store reproduces its OBSERVABLE effect instead — the
 * gate's read and the counter's write disagreeing — which is the exact
 * shape the real race produces.
 */
final class StaleReadArrayStore extends ArrayStore
{
    /** @var array<string, int> */
    private array $realCounts = [];

    public function __construct(private readonly int $frozenAttempts)
    {
        parent::__construct();
    }

    public function get($key)
    {
        if (str_ends_with((string) $key, ':timer')) {
            return parent::get($key);
        }

        return $this->frozenAttempts;
    }

    public function increment($key, $value = 1)
    {
        $key = (string) $key;
        $amount = is_numeric($value) ? (int) $value : 1;
        $current = $this->realCounts[$key] ?? 0;
        $this->realCounts[$key] = $current + $amount;

        return $this->realCounts[$key];
    }
}
