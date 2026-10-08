<?php

declare(strict_types=1);

/**
 * `interview:smoke-check` must not report a release the provider rejected.
 *
 * It printed "teardown(): OK" and exited 0 while `DELETE /sessions/{ref}`
 * answered 405 and the session kept running, because `Http` does not throw on a
 * 4xx. Everything here is `Http::fake`: the command is never run against the
 * live API from a test.
 */

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'interview.smoke_enabled' => true,
        'interview.heygen.api_key' => 'SMOKE_SECRET_HEYGEN_KEY_777',
    ]);
});

/**
 * @param  array<string, mixed>  $stop
 */
function fakeHeygenSmokeRun(int $stopStatus, array $stop = []): void
{
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-smoke']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => ['session_token' => 'tok-smoke', 'session_id' => '0b6d1f0c-6f58-4d4e-9d63-2f1d6a7c9a10'],
        ], 200),
        '*liveavatar*/sessions/stop*' => Http::response($stop, $stopStatus),
    ]);
}

test('smoke-check passes when the session is stopped', function (): void {
    fakeHeygenSmokeRun(200);

    $this->artisan('interview:smoke-check', ['--provider' => 'heygen'])
        ->expectsOutputToContain('teardown(): OK')
        ->expectsOutputToContain('PASSED')
        ->assertExitCode(0);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/sessions/stop'));
});

test('smoke-check fails, with a clear message, when the provider rejects the stop', function (): void {
    fakeHeygenSmokeRun(405, ['message' => 'Method Not Allowed '.'SMOKE_SECRET_HEYGEN_KEY_777']);

    $this->artisan('interview:smoke-check', ['--provider' => 'heygen'])
        ->expectsOutputToContain('teardown(): FAILED')
        ->doesntExpectOutputToContain('teardown(): OK')
        ->doesntExpectOutputToContain('PASSED')
        ->doesntExpectOutputToContain('SMOKE_SECRET_HEYGEN_KEY_777')
        ->assertExitCode(1);
});
