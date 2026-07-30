<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Writes the two cache keys App\Http\Controllers\QueueHealthController reads
 * (design.md D7). Listeners, not job code — the heartbeat/last-processed
 * signal must exist regardless of which job ran, and must survive the job
 * class itself changing.
 *
 * Heartbeat store MUST be cross-container (the worker process writes it,
 * the api process reads it via the health endpoint) — the `database` cache
 * store (config/cache.php:18) already is, so no new infrastructure.
 */
final class QueueRuntimeServiceProvider extends ServiceProvider
{
    public const HEARTBEAT_KEY = 'beai:queue:heartbeat';

    public const LAST_PROCESSED_AT_KEY = 'beai:queue:last_processed_at';

    /**
     * TTL comfortably longer than the sleep interval between Looping events
     * (queue.runtime.worker_sleep_seconds, default 3s) so a heartbeat never
     * expires between two consecutive loop iterations under normal
     * operation, while still expiring promptly if the worker process dies.
     *
     * Public: also used by App\Http\Controllers\QueueHealthController as
     * the freshness window for "alive" — a heartbeat value can still exist
     * in cache (TTL not yet expired) while being older than this, if the
     * cache write itself used a longer TTL than the refresh interval; the
     * health check must treat that as stale, not merely "present".
     */
    public const HEARTBEAT_TTL_SECONDS = 300;

    public function boot(): void
    {
        Event::listen(Looping::class, function (): void {
            Cache::put(self::HEARTBEAT_KEY, now()->getTimestamp(), self::HEARTBEAT_TTL_SECONDS);
        });

        Event::listen(JobProcessed::class, function (): void {
            Cache::put(self::LAST_PROCESSED_AT_KEY, now()->getTimestamp(), self::HEARTBEAT_TTL_SECONDS);
        });
    }
}
