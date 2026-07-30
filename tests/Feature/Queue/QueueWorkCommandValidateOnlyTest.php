<?php

declare(strict_types=1);

/**
 * `beai:queue-work --validate-only` (queue-worker-scheduler PR2, design.md
 * D1, task 5.1/6.1(b)(c)).
 *
 * Runs the SAME invariant PR1 encoded (tests/Unit/QueueRuntimeConfigTest.php)
 * against LIVE config and exits 0/1 WITHOUT starting the worker loop — lets
 * the container fail fast at startup instead of drifting into a bad
 * configuration at runtime. Uses RecordingQueueWorkCommand (defined in
 * QueueWorkCommandTest.php, same file-scope convention as that file) so a
 * regression that accidentally starts the real worker loop under
 * --validate-only cannot hang this test either.
 */

use App\Console\Commands\QueueWorkCommand;

test('--validate-only exits 0 when config satisfies the timeout/retry_after invariant and never starts the worker', function (): void {
    $command = new RecordingQueueWorkCommand;
    app()->instance(QueueWorkCommand::class, $command);

    $this->artisan('beai:queue-work', ['--validate-only' => true])
        ->assertExitCode(0);

    expect($command->recordedCalls)->toBe([]);
});

test('--validate-only exits non-zero when the invariant is violated and never starts the worker', function (): void {
    // Violate Assertion A: raise worker_timeout above retry_after.
    config()->set('queue.runtime.worker_timeout', 999999);

    $command = new RecordingQueueWorkCommand;
    app()->instance(QueueWorkCommand::class, $command);

    $this->artisan('beai:queue-work', ['--validate-only' => true])
        ->assertExitCode(1);

    expect($command->recordedCalls)->toBe([]);
});
