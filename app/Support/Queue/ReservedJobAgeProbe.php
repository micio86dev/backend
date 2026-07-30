<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Answers "how long has the oldest RESERVED job been reserved?" — a signal
 * plain queue depth cannot provide. A routine Railway restart mid-job
 * leaves that job reserved (picked up, not finished, not requeued) but
 * invisible to Queue::size() (pending count only) until retry_after
 * elapses (1500s — up to 25 minutes), during which the container reports
 * healthy while silently making zero progress on that candidate's
 * evaluation (queue-runtime/spec.md health-surface requirement,
 * reconciled against the 1500s reservation window via
 * queue.runtime.reserved_job_stall_threshold_seconds — see config/queue.php).
 */
final class ReservedJobAgeProbe
{
    /**
     * @param  string  $queueName  The queue to inspect (default: 'default' —
     *                             the only queue this app's worker consumes, per
     *                             queue.runtime.worker_queues).
     * @return int|null Age in seconds of the oldest reserved job, or null
     *                  if there are no reserved jobs OR the active driver has no
     *                  reservation concept (e.g. sync).
     */
    public function oldestReservedAgeSeconds(string $queueName = 'default'): ?int
    {
        $connection = Queue::connection();

        if ($connection instanceof DatabaseQueue) {
            return $this->fromDatabase($queueName);
        }

        if ($connection instanceof RedisQueue) {
            return $this->fromRedis($connection, $queueName);
        }

        return null;
    }

    private function fromDatabase(string $queueName): ?int
    {
        $table = (string) config('queue.connections.database.table', 'jobs');

        /** @var int|null $oldestReservedAt */
        $oldestReservedAt = DB::table($table)
            ->where('queue', $queueName)
            ->whereNotNull('reserved_at')
            ->min('reserved_at');

        if ($oldestReservedAt === null) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $oldestReservedAt);
    }

    /**
     * RedisQueue stores reserved jobs in a `{queue}:reserved` sorted set,
     * scored by the timestamp the job becomes AVAILABLE AGAIN
     * (reservedAt + retry_after — see
     * Illuminate\Queue\RedisQueue::retrieveNextJob(), which passes
     * $this->availableAt($this->retryAfter) as the score). The
     * lowest-scoring entry is therefore the job reserved EARLIEST
     * (retry_after is constant per connection, so score ordering preserves
     * reservation-time ordering); its reservation time is score -
     * retry_after.
     *
     * Uses the connection's own public getQueue() to build the key
     * (non-cluster-safe form — this app runs a single Redis instance, not a
     * cluster; getQueueRedisKey() is protected and adds cluster hash-tag
     * handling this app does not need).
     */
    private function fromRedis(RedisQueue $connection, string $queueName): ?int
    {
        $key = $connection->getQueue($queueName).':reserved';
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        /** @var array<string, mixed> $withScores */
        $withScores = $connection->getConnection()->zrange($key, 0, 0, true);

        if ($withScores === []) {
            return null;
        }

        $score = (int) array_values($withScores)[0];
        $reservedAt = $score - $retryAfter;

        return max(0, now()->getTimestamp() - $reservedAt);
    }
}
