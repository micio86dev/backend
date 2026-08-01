<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Guards the pgsql connection's session time zone.
 *
 * Laravel binds datetimes as offset-less strings ('Y-m-d H:i:s'). PostgreSQL
 * resolves such a literal against the SESSION TimeZone before storing it in a
 * `timestamptz` column. If the connection does not pin a zone it inherits the
 * SERVER's TimeZone, so the instant Laravel writes and the instant PostgreSQL
 * stores differ by that server's offset — silently, on every timestamptz write
 * in the application (`expires_at`, `last_used_at`, token expiries, …).
 *
 * This was a live defect: a developer machine running PostgreSQL on
 * Atlantic/Canary (+01:00 in summer) shifted every `api_clients.expires_at` by
 * an hour, which collapsed the revocation denylist TTL to its 1-second floor.
 *
 * The trap this file exists to close: CI's PostgreSQL container already runs on
 * UTC, so the round-trip assertion below passes there WITH OR WITHOUT the
 * config. Only the first test — which asserts the config key itself — fails in
 * CI when the `timezone` line is removed. It is deliberately a wiring
 * assertion, not a behavioural one, because no behavioural assertion can
 * detect the missing line on a server that happens to already be UTC.
 */
test('the pgsql connection pins its session time zone to UTC', function (): void {
    // config/app.php runs the application on UTC; the connection must agree.
    expect(config('app.timezone'))->toBe('UTC');
    expect(config('database.connections.pgsql.timezone'))->toBe('UTC');
});

test('the live pgsql session reports UTC, whatever the server default is', function (): void {
    expect(DB::selectOne('SHOW TimeZone')->TimeZone)->toBe('UTC');
});

test('a timestamptz column round-trips the exact instant it was given', function (): void {
    $org = Organization::factory()->create();

    // Whole seconds: the column is timestampTz at precision 0, so a fractional
    // instant would lose its microseconds for reasons unrelated to time zones
    // and muddy what this test is actually asserting.
    $written = Carbon::parse('2026-07-30 12:00:00', 'UTC');

    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'expires_at' => $written,
    ]);

    $read = $client->fresh()->expires_at;

    // Compare instants, not wall-clock strings: a shifted read-back can still
    // render the same 'Y-m-d H:i:s' while carrying a different offset, which is
    // precisely how the original defect stayed invisible.
    expect($read->getTimestamp())->toBe($written->getTimestamp());
});
