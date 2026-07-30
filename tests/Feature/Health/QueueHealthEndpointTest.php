<?php

declare(strict_types=1);

/**
 * GET /api/health/queue (queue-worker-scheduler PR4, design.md D7,
 * queue-runtime/spec.md "Queue Runtime Health Surface").
 *
 * Unauthenticated (Docker/Railway probes can't authenticate) — the body
 * carries counts/booleans/ages ONLY, never candidate or tenant identifiers.
 * Doubles as the worker HEALTHCHECK (wrapper PR5).
 */

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('200 ok when heartbeat is fresh, queue is not stalled, and there are no failed jobs', function (): void {
    Cache::put('beai:queue:heartbeat', now()->timestamp, 300);
    Cache::put('beai:queue:last_processed_at', now()->timestamp, 300);

    $response = $this->getJson('/api/health/queue');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('worker.alive', true)
        ->assertJsonPath('queue.stalled', false)
        ->assertJsonPath('queue.reservation_stalled', false)
        ->assertJsonPath('failed.count', 0);
});

test('503 down when the heartbeat is stale', function (): void {
    Cache::put('beai:queue:heartbeat', now()->subMinutes(10)->timestamp, 3000);

    $response = $this->getJson('/api/health/queue');

    $response->assertStatus(503)
        ->assertJsonPath('status', 'down')
        ->assertJsonPath('worker.alive', false);
});

test('503 down when the heartbeat was never recorded', function (): void {
    Cache::forget('beai:queue:heartbeat');

    $response = $this->getJson('/api/health/queue');

    $response->assertStatus(503)
        ->assertJsonPath('worker.alive', false);
});

test('response body contains no candidate- or tenant-identifying key', function (): void {
    Cache::put('beai:queue:heartbeat', now()->timestamp, 300);

    $response = $this->getJson('/api/health/queue');
    $flatKeys = collect(iterator_to_array(new RecursiveIteratorIterator(
        new RecursiveArrayIterator($response->json())
    ), false));

    // Explicit denylist — none of these may ever appear as a KEY anywhere
    // in the response, at any nesting depth.
    $denylist = ['participant_id', 'organization_id', 'tenant_id', 'candidate_id', 'email', 'name'];

    $allKeys = collect(array_keys(Arr::dot($response->json())));

    foreach ($denylist as $forbidden) {
        expect($allKeys->contains(fn (string $key): bool => str_contains($key, $forbidden)))->toBeFalse(
            "Response body must never contain the key fragment '{$forbidden}'."
        );
    }
});

test('failed.count reflects actual failed_jobs rows', function (): void {
    Cache::put('beai:queue:heartbeat', now()->timestamp, 300);

    DB::table('failed_jobs')->insert([
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Some exception',
            'failed_at' => now()->subMinutes(5),
        ],
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Another exception',
            'failed_at' => now()->subMinutes(20),
        ],
    ]);

    $response = $this->getJson('/api/health/queue');

    $response->assertJsonPath('failed.count', 2)
        ->assertJsonPath('status', 'degraded'); // worker alive, but failed jobs exist

    $oldestAge = $response->json('failed.oldest_age_seconds');
    expect($oldestAge)->toBeInt()->toBeGreaterThanOrEqual(1190); // ~20 min, tolerant
});

test('queue.depth reports the pending job count from the active connection', function (): void {
    Cache::put('beai:queue:heartbeat', now()->timestamp, 300);

    $response = $this->getJson('/api/health/queue');

    expect($response->json('queue.depth'))->toBeInt();
});

test('reservation_stalled is true and status is degraded when a reserved job exceeds the reserved_job_stall_threshold', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.runtime.reserved_job_stall_threshold_seconds', 1320);

    Cache::put('beai:queue:heartbeat', now()->timestamp, 300);
    Cache::put('beai:queue:last_processed_at', now()->timestamp, 300);

    $table = config('queue.connections.database.table', 'jobs');
    $now = now()->getTimestamp();

    DB::table($table)->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 1,
        'reserved_at' => $now - 1400, // exceeds the 1320s threshold
        'available_at' => $now,
        'created_at' => $now - 1400,
    ]);

    $response = $this->getJson('/api/health/queue');

    $response->assertJsonPath('queue.reservation_stalled', true)
        ->assertJsonPath('status', 'degraded');

    expect($response->json('queue.oldest_reserved_age_seconds'))->toBeGreaterThanOrEqual(1400);
});
