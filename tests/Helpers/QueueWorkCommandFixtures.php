<?php

declare(strict_types=1);

/**
 * Shared queue-worker-scheduler test fixtures.
 *
 * Live here, autoloaded via composer `autoload-dev.files`, NOT inside a test
 * file — used by BOTH tests/Feature/Queue/QueueWorkCommandTest.php and
 * tests/Feature/Queue/QueueWorkCommandValidateOnlyTest.php. CI runs
 * `php artisan test --parallel`, and ParaTest distributes test FILES across
 * worker processes: a class defined in one test file is simply not defined
 * in a worker that did not receive that file (see tests/Helpers/C10Fixtures.php
 * for the C10 precedent that caught this the hard way).
 *
 * SAFETY: `queue:work` (the command QueueWorkCommand delegates to) is a
 * daemon that loops forever absent --once/--stop-when-empty.
 * RecordingQueueWorkCommand overrides call() to record the delegated
 * command+arguments instead of ever actually running them — every test that
 * resolves QueueWorkCommand from the container MUST swap this in first, or
 * a regression under test could hang the test process for real.
 */

use App\Console\Commands\QueueWorkCommand;

final class RecordingQueueWorkCommand extends QueueWorkCommand
{
    /** @var list<array{0: string, 1: array<string,string>}> */
    public array $recordedCalls = [];

    public function call($command, array $arguments = []): int
    {
        $this->recordedCalls[] = [$command, $arguments];

        return 0;
    }
}
