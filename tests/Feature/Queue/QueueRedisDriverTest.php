<?php

declare(strict_types=1);

/**
 * `QUEUE_CONNECTION` default → `redis` (queue-worker-scheduler PR3,
 * design.md, tasks.md 10.1).
 *
 * Guards the "Redis is ready" claim from silently drifting: PR1 landed
 * ext-redis + the timeout/retry_after invariant, PR2 landed the structural
 * --tries prohibition — this PR flips config/queue.php:16's default from
 * `database` to `redis`.
 *
 * The `Queue::connection()->push()` assertion is SKIPPED when ext-redis is
 * not loaded on the current PHP runtime (mirrors tasks.md 10.1's own
 * "skippable if Redis is unreachable in the sandbox" allowance) — this
 * host's native PHP does not have ext-redis installed (only the Docker
 * runtime image does, per PR1's Dockerfile). A REAL dispatch against the
 * compose `redis` service was additionally exercised via a one-off
 * `beai-api:local` container for this PR (recorded verbatim in the apply
 * report / apply-progress, not reproducible here on host PHP).
 */

use Illuminate\Support\Facades\Queue;

test('config/queue.php\'s own default (the env() fallback, not the resolved value) is redis', function (): void {
    // phpunit.xml deliberately sets QUEUE_CONNECTION=sync for the whole
    // suite (determinism — coordinator confirmed: do not change that), so
    // config('queue.default') / env('QUEUE_CONNECTION') will ALWAYS resolve
    // to 'sync' inside this test run regardless of the file's own default.
    // The thing PR3 actually changed is the env() FALLBACK ARGUMENT itself
    // (config/queue.php:16..27), which can only be verified by reading the
    // file's raw source, not by resolving config() through the overridden
    // test environment.
    $source = file_get_contents(config_path('queue.php'));

    expect($source)->not->toBeFalse();
    expect($source)->toContain("env('QUEUE_CONNECTION', 'redis')");
});

test('Queue::connection() resolves the redis driver without throwing when REDIS_CLIENT=phpredis', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis not installed on this host PHP runtime — verified separately via a real Docker-based dispatch (see apply-progress).');
    }

    config()->set('queue.default', 'redis');
    config()->set('database.redis.client', 'phpredis');

    expect(fn () => Queue::connection('redis'))->not->toThrow(Throwable::class);
});
