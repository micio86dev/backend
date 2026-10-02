<?php

declare(strict_types=1);

/**
 * GET /api/health/ready (stack-schema-drift-guard): readiness = reachable DB
 * and no pending migration. Complements HealthTest, which pins liveness
 * (GET /api/health) as DB-free on purpose. Runs against the real testing
 * database, with real-path mutations rolled back in `finally`.
 */

use Illuminate\Support\Facades\DB;

/** Run $fn with the latest `migrations` row removed, then roll the delete back. */
function withLatestMigrationRowRemoved(Closure $fn): mixed
{
    $latest = DB::table('migrations')->max('id');
    expect($latest)->not->toBeNull();

    DB::beginTransaction();
    try {
        DB::table('migrations')->where('id', $latest)->delete();

        return $fn();
    } finally {
        DB::rollBack();
        expect(DB::table('migrations')->where('id', $latest)->exists())->toBeTrue();
    }
}

it('answers 200 {"status":"ok"} on a migrated database', function (): void {
    $this->getJson('/api/health/ready')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['status' => 'ok']);
});

it('answers 503 pending_migrations when a migration row is missing', function (): void {
    withLatestMigrationRowRemoved(function (): void {
        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['status' => 'down', 'reason' => 'pending_migrations']);
    });
});

it('never leaks a migration name, SQL or host in the pending response', function (): void {
    $latestName = (string) DB::table('migrations')->orderByDesc('id')->value('migration');

    $body = withLatestMigrationRowRemoved(
        fn (): string => (string) $this->getJson('/api/health/ready')->getContent()
    );

    expect($body)->not->toContain($latestName)
        ->and($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain((string) config('database.connections.pgsql.host'));
});

it('answers 503 database_unavailable when the database cannot be reached, without leaking details', function (): void {
    $original = config('database.connections.pgsql');

    try {
        config([
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 1, // nothing listens here: immediate refusal
        ]);
        DB::purge();

        $response = $this->getJson('/api/health/ready');
        $body = (string) $response->getContent();

        $response->assertStatus(503)
            ->assertExactJson(['status' => 'down', 'reason' => 'database_unavailable']);
        expect($body)->not->toContain('SQLSTATE')
            ->and($body)->not->toContain('127.0.0.1');
    } finally {
        config(['database.connections.pgsql' => $original]);
        DB::purge();
    }

    expect(DB::selectOne('select 1 as ok')->ok)->toBe(1);
});

it('keeps liveness DB-free: /api/health is 200 with zero queries while readiness is 503', function (): void {
    withLatestMigrationRowRemoved(function (): void {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/api/health')
            ->assertStatus(200)
            ->assertExactJson(['status' => 'ok']);

        expect(DB::getQueryLog())->toBe([]);

        $this->getJson('/api/health/ready')->assertStatus(503);
        expect(DB::getQueryLog())->not->toBe([]);

        DB::disableQueryLog();
    });
});
