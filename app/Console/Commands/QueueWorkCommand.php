<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Queue\QueueRuntimeInvariant;
use Illuminate\Console\Command;

/**
 * beai:queue-work — the ONLY supported worker entrypoint (design.md D1).
 *
 * Delegates to the framework's `queue:work` with every reliability number
 * sourced from config('queue.runtime.*') — no numeric flags are ever passed
 * by the caller (compose `command:`, Railway start command, etc.).
 *
 * Structurally enforces the retry-ownership prohibition
 * (queue-runtime/spec.md Requirement 4, tests/Arch/Queue/QueuedJobRetryOwnershipArchTest.php):
 * `--tries` is NEVER a defined option on this signature. A flag that does
 * not exist cannot be forwarded — Symfony Console's own option validation
 * rejects an unrecognized `--tries` with a non-zero exit BEFORE handle()
 * ever runs, and therefore before any job is reserved. A worker-level
 * `--tries` would remove the safety net for any job that ever fails to
 * declare its own retry policy (DeliverWebhookJob owns a 6-attempt
 * pending -> dead state machine — see app/Jobs/DeliverWebhookJob.php class
 * doc — that a framework cap could otherwise short-circuit).
 *
 * --validate-only runs the SAME invariant PR1 encoded
 * (App\Support\Queue\QueueRuntimeInvariant) against live config and exits
 * 0/1 WITHOUT starting the worker loop — lets the container fail fast at
 * startup instead of drifting into a bad configuration at runtime.
 *
 * REQ: Job-Level Retry Ownership + Timeout/Retry-After Ordering and Ceiling
 * Invariant (queue-runtime/spec.md)
 */
class QueueWorkCommand extends Command
{
    protected $signature = 'beai:queue-work
                            {--validate-only : Validate the timeout/retry_after invariant and exit without starting the worker}';

    protected $description = 'Start the queue worker with every reliability number sourced from config(queue.runtime.*) — the only supported worker entrypoint';

    public function handle(QueueRuntimeInvariant $invariant): int
    {
        if ($this->option('validate-only')) {
            return $this->runValidation($invariant);
        }

        return (int) $this->call('queue:work', [
            '--timeout' => (string) config('queue.runtime.worker_timeout'),
            '--max-time' => (string) config('queue.runtime.worker_max_time'),
            '--memory' => (string) config('queue.runtime.worker_memory_mb'),
            '--queue' => implode(',', (array) config('queue.runtime.worker_queues')),
            '--sleep' => (string) config('queue.runtime.worker_sleep_seconds'),
        ]);
    }

    private function runValidation(QueueRuntimeInvariant $invariant): int
    {
        $violations = $invariant->violations();

        if ($violations === []) {
            $this->info('queue runtime invariant holds.');

            return self::SUCCESS;
        }

        foreach ($violations as $violation) {
            $this->error($violation);
        }

        return self::FAILURE;
    }
}
