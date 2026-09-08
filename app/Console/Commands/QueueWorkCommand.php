<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mail\MailDeliveryProbe;
use App\Support\Queue\QueueRuntimeInvariant;
use Illuminate\Console\Command;

/**
 * beai:queue-work — the ONLY supported worker entrypoint (design.md D1).
 *
 * Delegates to the framework's `queue:work` with every reliability number
 * sourced from config('queue.runtime.*') — no numeric flags are ever passed
 * by the caller (compose `command:`, Railway start command, etc.).
 *
 * `--tries` is NEVER a defined option on this signature. A flag that does
 * not exist cannot be forwarded — Symfony Console's own option validation
 * rejects an unrecognized `--tries` with a non-zero exit BEFORE handle()
 * ever runs, and therefore before any job is reserved
 * (tests/Feature/Queue/QueueWorkCommandTest.php exercises exactly that).
 *
 * WHAT THIS DOES AND DOES NOT PROTECT — verified against vendor/, because an
 * earlier revision of this docblock asserted a Laravel precedence rule that
 * does not exist and would have misled anyone weakening the arch test:
 *
 *   A job that declares its OWN tries is already immune to any worker-level
 *   `--tries`. `maxTries` is baked into the job payload at DISPATCH time from
 *   the job's own $tries/tries()
 *   (Illuminate\Queue\Queue::createObjectPayload() line 176 -> getJobTries()
 *   line 230; Illuminate\Queue\Jobs\Job::maxTries() line 294 reads it back),
 *   and the worker then prefers it unconditionally:
 *   `$maxTries = ! is_null($job->maxTries()) ? $job->maxTries() : $maxTries;`
 *   in BOTH Illuminate\Queue\Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()
 *   line 639 (the post-timeout path) and ::markJobAsFailedIfWillExceedMaxAttempts()
 *   line 667 (the normal failure path). DeliverWebhookJob declares tries()
 *   (app/Jobs/DeliverWebhookJob.php:80), so its 6-attempt pending -> dead state
 *   machine could NOT have been short-circuited by a worker `--tries` in the
 *   first place. Omitting `--tries` here is not what protects it.
 *
 *   What the omission genuinely prevents is narrower and still worth having:
 *   an operator typing `beai:queue-work --tries=N` and having it silently
 *   forwarded. (It is also inert by default either way — since handle() never
 *   passes `--tries`, the delegated `queue:work` just takes its own framework
 *   default of 1, Illuminate\Queue\Console\WorkCommand line 50.)
 *
 *   The real protection against a job inheriting a worker-level default is a
 *   DIFFERENT mechanism, and it is the one to preserve:
 *   tests/Arch/Queue/QueuedJobRetryOwnershipArchTest.php, which fails the build
 *   if any ShouldQueue class omits its own $tries/tries() — because a job that
 *   declares nothing has a null payload maxTries and DOES fall through to the
 *   worker's value (queue-runtime/spec.md Requirement 4).
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

    public function handle(QueueRuntimeInvariant $invariant, MailDeliveryProbe $mail): int
    {
        if ($this->option('validate-only')) {
            return $this->runValidation($invariant);
        }

        if (! $this->mailCanBeDelivered($mail)) {
            return self::FAILURE;
        }

        return (int) $this->call('queue:work', [
            '--timeout' => (string) config('queue.runtime.worker_timeout'),
            '--max-time' => (string) config('queue.runtime.worker_max_time'),
            '--memory' => (string) config('queue.runtime.worker_memory_mb'),
            '--queue' => implode(',', (array) config('queue.runtime.worker_queues')),
            '--sleep' => (string) config('queue.runtime.worker_sleep_seconds'),
        ]);
    }

    /**
     * Refuse to start a worker that cannot deliver mail (mail-delivery-guard).
     *
     * EVERY notification in this system is queued, so this process is the only
     * one that sends. The Railway `worker` service had no `MAIL_MAILER`,
     * `config/mail.php:17` resolved it to `log`, and invitations, password
     * resets and operator alerts were written to a log file and reported as
     * delivered — for months, with a green health check throughout.
     *
     * `MailSelfTestCommand` could already detect exactly this, and was never
     * run. That is the argument for a gate that runs by itself: a
     * misconfigured deploy fails to start, loudly, instead of silently
     * discarding every message.
     *
     * PRODUCTION ONLY, and that is not timidity. `log` and `array` are the
     * correct answers locally and in CI — `phpunit.xml` pins `array`
     * deliberately so the suite never sends — and a gate that failed the
     * suite would be switched off within a day and protect nothing.
     */
    private function mailCanBeDelivered(MailDeliveryProbe $mail): bool
    {
        if (! $this->getLaravel()->environment('production')) {
            return true;
        }

        $refusal = $mail->refusal();

        if ($refusal === null) {
            return true;
        }

        $this->error('Refusing to start: this worker cannot deliver mail.');
        $this->line('');
        $this->warn('  '.$refusal->detail);
        $this->warn('  Every queued notification would be accepted and reach no one.');
        $this->line('');
        $this->line('  Set MAIL_MAILER on THIS service. It is a separate Railway service with');
        $this->line('  its own variables, and it is the one that actually sends.');
        $this->line('  Verify with: php artisan beai:mail-selftest --to=you@real.tld');

        return false;
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
