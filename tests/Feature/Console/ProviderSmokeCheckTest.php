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
 * Method-aware on purpose: `POST /contexts` creates, `DELETE /contexts/{id}` deletes, and a fake that
 * answered both alike would pass a wrong verb (a DELETE that really answers 405 once hid behind one).
 *
 * @param  array<string, mixed>  $stop
 */
function fakeHeygenSmokeRun(int $stopStatus, array $stop = [], int $contextDeleteStatus = 200): void
{
    Http::fake([
        '*liveavatar*/contexts*' => fn ($request) => $request->method() === 'DELETE'
            ? Http::response(['message' => 'ctx delete'], $contextDeleteStatus)
            : Http::response(['data' => ['id' => 'ctx-smoke']], 200),
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
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/contexts/ctx-smoke'));
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

test('smoke-check fails, with a clear message, when the context delete is not confirmed', function (int $status): void {
    fakeHeygenSmokeRun(200, [], $status);

    $this->artisan('interview:smoke-check', ['--provider' => 'heygen'])
        ->expectsOutputToContain('context delete: FAILED — the [heygen] API did not confirm the delete of context [ctx-smoke]')
        ->doesntExpectOutputToContain('PASSED')
        ->doesntExpectOutputToContain('SMOKE_SECRET_HEYGEN_KEY_777')
        ->assertExitCode(1);
})->with([405, 500]);

test('smoke-check treats an already-gone context (404) as deleted', function (): void {
    fakeHeygenSmokeRun(200, [], 404);

    $this->artisan('interview:smoke-check', ['--provider' => 'heygen'])
        ->expectsOutputToContain('PASSED')
        ->assertExitCode(0);
});
