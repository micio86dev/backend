<?php

declare(strict_types=1);

/**
 * CI Tier 2 — Real-Worker Verification (queue-worker-scheduler PR4, design.md
 * D8, queue-runtime/spec.md "CI Verifies the Real-Worker Path").
 *
 * `sync` stays the default driver for the rest of the suite (deterministic;
 * the null-org bug class this codebase has hit before reproduces under
 * `sync` because Queue::before fires there too) — this ONE file is the
 * exception, dispatching onto the REAL `redis` connection and draining with
 * a REAL `queue:work` invocation, because nothing else proves the redis
 * driver actually serializes/deserializes our jobs and preserves the
 * queue-context tenancy contract (Queue::before resets ambient
 * TenantResolver state; TenantContextScope::runFor() re-establishes it).
 *
 * Runs IN-PROCESS via Artisan::call() (--once bounds it — queue:work is a
 * daemon that loops forever without --once/--stop-when-empty, and the
 * fixture job below is only autoloadable under the dev/test autoloader, so
 * a subprocess spawned outside this test run would not find it anyway).
 *
 * Skips when ext-redis is unavailable — this host's native PHP does not
 * have it (only the Docker runtime image does, per PR1's Dockerfile);
 * CI's dedicated Tier 2 job (ci.yml) DOES install redis/pcntl/posix via
 * setup-php specifically so this file is not skipped there.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Queue\TenancyProofJob;

test('a job dispatched under the real redis driver drains, and TenantContextScope establishes tenant context that Queue::before had reset to null', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis not installed on this host PHP runtime — exercised by CI Tier 2 (ci.yml) instead.');
    }

    config()->set('queue.default', 'redis');

    $prefix = 'ci_tier2_'.uniqid();
    $organizationId = 42;

    Queue::connection('redis')->push(new TenancyProofJob($organizationId, $prefix));

    expect(Queue::connection('redis')->size('default'))->toBe(1);

    $exitCode = Artisan::call('queue:work', [
        'connection' => 'redis',
        '--once' => true,
        '--stop-when-empty' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Queue::connection('redis')->size('default'))->toBe(0); // (a) drained

    // (b) TenantContextScope::runFor() established context that
    // Queue::before had reset to null beforehand — under the REAL redis
    // driver, not sync.
    expect(Cache::get("{$prefix}:before"))->toBe('null')
        ->and(Cache::get("{$prefix}:during"))->toBe($organizationId);
});
