<?php

declare(strict_types=1);

/**
 * `beai:queue-work` (queue-worker-scheduler PR2, design.md D1,
 * queue-runtime/spec.md Requirement 4).
 *
 * `--tries` is NEVER a defined option on this command's signature — the
 * structural enforcement of the retry-ownership prohibition. A flag that
 * does not exist cannot be forwarded to the underlying worker loop.
 *
 * SAFETY NOTE: `queue:work` (the command this wrapper delegates to via
 * Command::call()) is a daemon that loops forever absent
 * `--once`/`--stop-when-empty`. Every test below that could reach
 * Command::handle() therefore swaps in RecordingQueueWorkCommand (shared
 * fixture, tests/Helpers/QueueWorkCommandFixtures.php — required to be
 * autoloaded, not test-file-local, since QueueWorkCommandValidateOnlyTest.php
 * also uses it and ParaTest distributes test files across workers), which
 * overrides call() to record the delegated command+arguments instead of
 * ever actually running them. This is not optional mocking-for-speed — an
 * earlier draft of this file used Mockery::mock()->makePartial() on the
 * real class and, under the `--tries` mutation described below, let a real
 * `queue:work` invocation start and hang the test process (Mockery partial
 * mocks by class name also don't call the real constructor, so
 * Symfony\Component\Console\Command's InputDefinition was never
 * initialized — a second, independent reason to avoid that approach here).
 */

use App\Console\Commands\QueueWorkCommand;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\InvalidOptionException;

test('beai:queue-work has no --tries option in its definition', function (): void {
    $command = app(QueueWorkCommand::class);

    expect($command->getDefinition()->hasOption('tries'))->toBeFalse();
});

test('beai:queue-work --tries is rejected at option-parsing time, before Command::handle() ever runs', function (): void {
    $command = new RecordingQueueWorkCommand;
    app()->instance(QueueWorkCommand::class, $command);

    // Artisan::call() (unlike the real `php artisan` CLI entrypoint) does not
    // catch console exceptions — proving this throws proves the rejection
    // happens during Input::bind()/parse(), strictly BEFORE handle() is ever
    // invoked, i.e. before any job could possibly be reserved.
    expect(fn () => Artisan::call('beai:queue-work', ['--tries' => 3]))
        ->toThrow(InvalidOptionException::class, 'The "--tries" option does not exist.');

    expect($command->recordedCalls)->toBe([]);
});

test('beai:queue-work forwards --timeout/--max-time/--memory/--queue/--sleep from config(queue.runtime.*) to queue:work', function (): void {
    config()->set('queue.runtime.worker_timeout', 1260);
    config()->set('queue.runtime.worker_max_time', 3600);
    config()->set('queue.runtime.worker_memory_mb', 512);
    config()->set('queue.runtime.worker_queues', ['default']);
    config()->set('queue.runtime.worker_sleep_seconds', 3);

    $command = new RecordingQueueWorkCommand;
    app()->instance(QueueWorkCommand::class, $command);

    $this->artisan('beai:queue-work')->assertExitCode(0);

    expect($command->recordedCalls)->toBe([
        ['queue:work', [
            '--timeout' => '1260',
            '--max-time' => '3600',
            '--memory' => '512',
            '--queue' => 'default',
            '--sleep' => '3',
        ]],
    ]);
});

test('beai:queue-work forwards a comma-joined --queue when multiple worker_queues are configured', function (): void {
    config()->set('queue.runtime.worker_queues', ['default', 'webhooks']);

    $command = new RecordingQueueWorkCommand;
    app()->instance(QueueWorkCommand::class, $command);

    $this->artisan('beai:queue-work')->assertExitCode(0);

    expect($command->recordedCalls)->toHaveCount(1)
        ->and($command->recordedCalls[0][1]['--queue'])->toBe('default,webhooks');
});
