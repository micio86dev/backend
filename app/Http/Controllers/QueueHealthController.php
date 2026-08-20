<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\RedisEvictionPolicyProbe;
use App\Providers\QueueRuntimeServiceProvider;
use App\Support\Queue\ReservedJobAgeProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * GET /api/health/queue (design.md D7, queue-runtime/spec.md "Queue Runtime
 * Health Surface"). Unauthenticated (Docker/Railway probes can't
 * authenticate) — the body carries counts/booleans/ages ONLY, never a
 * candidate or tenant identifier. Doubles as the worker HEALTHCHECK
 * (wrapper PR5).
 *
 * Three states:
 *   - down (503): the worker heartbeat is stale or missing — the worker
 *     process is not running at all. A real outage.
 *   - degraded (200): the worker IS alive, but something needs attention —
 *     depth-based stall, reservation-based stall (see
 *     App\Support\Queue\ReservedJobAgeProbe), or a non-zero failed count.
 *     Visible to a human/dashboard without tripping infra-level hard-down
 *     alerting on its own.
 *   - ok (200): worker alive, no stall signal, zero failed jobs.
 *
 * NOT included: candidate/tenant identifiers, job payloads, exception
 * bodies (only exists to reflect COUNTS and AGES).
 */
class QueueHealthController extends Controller
{
    public function __invoke(ReservedJobAgeProbe $reservedJobAgeProbe, RedisEvictionPolicyProbe $evictionPolicyProbe): JsonResponse
    {
        // backoffice-session-refresh-hardening D3: surfaced on EVERY response
        // (not only the "ok" branch) so eviction-policy drift is observable
        // even while the worker itself is down.
        $redisEvictionPolicy = $evictionPolicyProbe->resolve();

        $heartbeatAt = Cache::get(QueueRuntimeServiceProvider::HEARTBEAT_KEY);
        $heartbeatAgeSeconds = $heartbeatAt !== null ? max(0, now()->getTimestamp() - (int) $heartbeatAt) : null;

        // "Alive" requires both presence AND freshness — a heartbeat value
        // that still exists in cache (TTL not yet expired) but is older
        // than the Looping interval it should refresh on every few seconds
        // means the worker stopped looping without the cache entry having
        // expired yet (e.g. a long TTL write followed by a crash).
        $alive = $heartbeatAgeSeconds !== null && $heartbeatAgeSeconds <= QueueRuntimeServiceProvider::HEARTBEAT_TTL_SECONDS;

        if (! $alive) {
            return response()->json([
                'status' => 'down',
                'worker' => [
                    'alive' => false,
                    'last_heartbeat_age_seconds' => $heartbeatAgeSeconds,
                ],
                'queue' => null,
                'failed' => null,
                'redis_eviction_policy' => $redisEvictionPolicy,
            ], 503);
        }

        $depth = (int) Queue::connection()->size('default');

        $lastProcessedAt = Cache::get(QueueRuntimeServiceProvider::LAST_PROCESSED_AT_KEY);
        $lastProcessedAgeSeconds = $lastProcessedAt !== null ? max(0, now()->getTimestamp() - (int) $lastProcessedAt) : null;

        $stallThreshold = (int) config('queue.runtime.stall_threshold_seconds');
        $stalled = $depth > 0 && ($lastProcessedAgeSeconds === null || $lastProcessedAgeSeconds > $stallThreshold);

        $oldestReservedAgeSeconds = $reservedJobAgeProbe->oldestReservedAgeSeconds();
        $reservedStallThreshold = (int) config('queue.runtime.reserved_job_stall_threshold_seconds');
        $reservationStalled = $oldestReservedAgeSeconds !== null && $oldestReservedAgeSeconds > $reservedStallThreshold;

        $failedCount = (int) DB::table('failed_jobs')->count();
        $oldestFailedAt = DB::table('failed_jobs')->min('failed_at');
        $failedOldestAgeSeconds = $oldestFailedAt !== null
            ? max(0, now()->getTimestamp() - Carbon::parse($oldestFailedAt)->getTimestamp())
            : null;

        $degraded = $stalled || $reservationStalled || $failedCount > 0;

        return response()->json([
            'status' => $degraded ? 'degraded' : 'ok',
            'worker' => [
                'alive' => true,
                'last_heartbeat_age_seconds' => $heartbeatAgeSeconds,
            ],
            'queue' => [
                'depth' => $depth,
                'last_processed_age_seconds' => $lastProcessedAgeSeconds,
                'stalled' => $stalled,
                'oldest_reserved_age_seconds' => $oldestReservedAgeSeconds,
                'reservation_stalled' => $reservationStalled,
            ],
            'failed' => [
                'count' => $failedCount,
                'oldest_age_seconds' => $failedOldestAgeSeconds,
            ],
            'redis_eviction_policy' => $redisEvictionPolicy,
        ], 200);
    }
}
