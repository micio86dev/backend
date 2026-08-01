<?php

declare(strict_types=1);

/**
 * App\Support\Queue\ReservedJobAgeProbe (queue-worker-scheduler PR4).
 *
 * Answers a signal the health probe's plain queue-depth metric cannot:
 * a routine Railway restart mid-ScoreEvaluationJob leaves that job
 * RESERVED (picked up, not yet finished) but invisible to `Queue::size()`
 * (which only counts PENDING jobs) for up to retry_after (1500s) — up to
 * 25 minutes where a healthy-looking container is silently not making
 * progress on a candidate's evaluation, directly threatening the p95 < 10
 * min scoring target.
 *
 * Database driver: `jobs.reserved_at` is a raw column — trivial to query.
 * Redis driver: verified separately via Docker (host PHP lacks ext-redis,
 * same constraint as PR3's real-dispatch verification) — see apply report.
 * Unsupported drivers (sync, etc.): returns null — no reservation concept.
 */

use App\Support\Queue\ReservedJobAgeProbe;
use Illuminate\Support\Facades\DB;

test('returns null when the queue table has no reserved jobs', function (): void {
    config()->set('queue.default', 'database');

    $probe = new ReservedJobAgeProbe;

    expect($probe->oldestReservedAgeSeconds())->toBeNull();
});

test('returns the age in seconds of the oldest reserved job on the database driver', function (): void {
    config()->set('queue.default', 'database');

    $table = config('queue.connections.database.table', 'jobs');
    $now = now()->getTimestamp();

    // Newer reservation (should NOT be the one reported).
    DB::table($table)->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 1,
        'reserved_at' => $now - 30,
        'available_at' => $now,
        'created_at' => $now - 30,
    ]);

    // Oldest reservation — this is the one that must be reported.
    DB::table($table)->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 1,
        'reserved_at' => $now - 900,
        'available_at' => $now,
        'created_at' => $now - 900,
    ]);

    // Pending (not reserved) — must be ignored entirely.
    DB::table($table)->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $now,
        'created_at' => $now,
    ]);

    $probe = new ReservedJobAgeProbe;
    $age = $probe->oldestReservedAgeSeconds();

    expect($age)->toBeInt()
        ->and($age)->toBeGreaterThanOrEqual(900)
        ->and($age)->toBeLessThan(910); // small tolerance for test execution time
});

test('returns null for a driver with no reservation concept', function (): void {
    config()->set('queue.default', 'sync');

    $probe = new ReservedJobAgeProbe;

    expect($probe->oldestReservedAgeSeconds())->toBeNull();
});
