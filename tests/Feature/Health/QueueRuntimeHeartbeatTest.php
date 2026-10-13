<?php

declare(strict_types=1);

/**
 * QueueRuntimeServiceProvider stamps two cache keys the health endpoint reads:
 * a heartbeat on every worker loop, and the time the last job finished.
 */

use App\Providers\QueueRuntimeServiceProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

test('a worker loop iteration refreshes the heartbeat but not the last-processed marker', function (): void {
    $this->freezeTime();

    Event::dispatch(new Looping('redis', 'default'));

    expect(Cache::get(QueueRuntimeServiceProvider::HEARTBEAT_KEY))->toBe(now()->getTimestamp())
        ->and(Cache::has(QueueRuntimeServiceProvider::LAST_PROCESSED_AT_KEY))->toBeFalse();
});

test('a finished job stamps the last-processed marker', function (): void {
    $this->freezeTime();

    Event::dispatch(new JobProcessed('redis', Mockery::mock(Job::class)));

    expect(Cache::get(QueueRuntimeServiceProvider::LAST_PROCESSED_AT_KEY))->toBe(now()->getTimestamp());
});
