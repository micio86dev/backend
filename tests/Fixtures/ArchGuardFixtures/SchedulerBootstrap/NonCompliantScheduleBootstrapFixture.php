<?php

declare(strict_types=1);

namespace Tests\Fixtures\ArchGuardFixtures\SchedulerBootstrap;

use App\Jobs\FinalizeInterview;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Controlled fixture for tests/Arch/Queue/SchedulerOnOneServerArchTest.php.
 *
 * This class is NEVER INSTANTIATED and configure() is NEVER CALLED. The file
 * exists to be read as raw TEXT (file_get_contents) by the arch guard's
 * extractor + scanner, exactly the way it reads bootstrap/app.php — which is
 * why the ->withSchedule(...) block below is written as real, valid,
 * pint-formatted PHP rather than a heredoc blob: a fixture that cannot drift
 * into unparseable garbage is worth more than one that only looks like code.
 *
 * It deliberately mixes COMPLIANT and NON-COMPLIANT registrations across all
 * four Illuminate\Console\Scheduling\Schedule entry points, so the guard's
 * positive proof covers both directions:
 *
 *   - command() WITHOUT onOneServer()  -> MUST be reported
 *   - call()    WITH    onOneServer()  -> MUST NOT be reported
 *   - job()     WITHOUT onOneServer()  -> MUST be reported (the exact blind
 *     spot an adversarial review found: the pre-fix guard recognised only
 *     command/call, so this line passed green against the real bootstrap/app.php)
 *   - exec()    WITHOUT onOneServer()  -> MUST be reported (same blind spot)
 *   - job()     WITH    onOneServer()  -> MUST NOT be reported
 *
 * Using a fixture file — never a temporary mutation of the real
 * bootstrap/app.php — is what makes that proof a PERMANENT regression test
 * instead of a one-off manual check (same rationale as
 * tests/Fixtures/ArchGuardFixtures/RetryOwnershipJobs).
 */
final class NonCompliantScheduleBootstrapFixture
{
    /**
     * Never invoked. $app is intentionally untyped-by-contract (a plain
     * object) so this fixture never depends on the real Application
     * configuration surface — only its SOURCE TEXT matters.
     */
    public static function configure(object $app): void
    {
        $app->withSchedule(function (Schedule $schedule): void {
            // VIOLATION — command() with no onOneServer().
            $schedule->command('queue:prune-failed', ['--hours' => 168])->dailyAt('03:10');

            // COMPLIANT — call() with onOneServer(), and an internal
            // semicolon inside the closure body to keep the chain-boundary
            // scanner honest in the same pass.
            $schedule->call(function (): void {
                $noop = 1;
            })->everyMinute()->onOneServer();

            // VIOLATION — job(): a scheduled job class, the ordinary way to
            // implement something like C13's GDPR purge sweep.
            $schedule->job(new FinalizeInterview(1))->dailyAt('04:00');

            // VIOLATION — exec(): a scheduled shell command.
            $schedule->exec('php artisan inspire')->dailyAt('04:10');

            // COMPLIANT — job() with onOneServer().
            $schedule->job(new FinalizeInterview(2))->dailyAt('04:20')->onOneServer();
        });
    }
}
