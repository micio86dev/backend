<?php

declare(strict_types=1);

/**
 * Architecture guard: every scheduled task registered inside the
 * ->withSchedule() closure in api/bootstrap/app.php MUST chain
 * ->onOneServer() (design.md D6, queue-runtime/spec.md Requirement 7 —
 * "Scheduler Pinned to a Single Active Replica").
 *
 * PR2 registers the scheduler RUNNER only — no tasks yet (the first real
 * consumer is C13's GDPR purge sweep, not yet implemented). This guard is a
 * structural regression lock for whenever a future change DOES add a task:
 * `deploy.replicas: 1` (docker-compose, wrapper PR5) is overridable by
 * --scale, so onOneServer() (backed by a real DB lock —
 * Illuminate\Cache\DatabaseStore implements LockProvider, cache_locks table
 * already exists) is the structural defense-in-depth against a double-run,
 * not just documentation.
 *
 * Operates on a raw PHP-source STRING rather than a fixture file tree
 * (PR1's convention) — the object under guard here is a single closure body,
 * not a directory of classes — but keeps the same "prove it can fail before
 * trusting it" discipline via the synthetic-snippet proof test below.
 *
 * REQ: Scheduler Pinned to a Single Active Replica (queue-runtime/spec.md)
 */

/**
 * Scan $closureSource (the raw text of a ->withSchedule() closure body) for
 * every `$schedule->command(...)` / `$schedule->call(...)` chain (from the
 * call up to its terminating `;`) and return the raw text of any chain that
 * does NOT contain `onOneServer(`.
 *
 * @return list<string>
 */
function qwsSchedulerOnOneServerViolations(string $closureSource): array
{
    $violations = [];

    if (preg_match_all('/\$schedule->(?:command|call)\([^;]*;/s', $closureSource, $matches) === false) {
        return [];
    }

    foreach ($matches[0] as $chain) {
        if (! str_contains($chain, 'onOneServer(')) {
            $violations[] = trim($chain);
        }
    }

    return $violations;
}

/**
 * Extract the withSchedule(function (Schedule $schedule): void { ... })
 * closure body from bootstrap/app.php's raw source via brace-counting (the
 * closure may itself contain nested braces from any task's own closures).
 */
function qwsExtractWithScheduleClosureBody(string $bootstrapAppSource): ?string
{
    $start = strpos($bootstrapAppSource, '->withSchedule(');

    if ($start === false) {
        return null;
    }

    $braceOpen = strpos($bootstrapAppSource, '{', $start);

    if ($braceOpen === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($bootstrapAppSource);

    for ($i = $braceOpen; $i < $length; $i++) {
        if ($bootstrapAppSource[$i] === '{') {
            $depth++;
        } elseif ($bootstrapAppSource[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($bootstrapAppSource, $braceOpen + 1, $i - $braceOpen - 1);
            }
        }
    }

    return null;
}

test('bootstrap/app.php withSchedule() closure has zero scheduled tasks lacking onOneServer()', function (): void {
    $source = file_get_contents(base_path('bootstrap/app.php'));

    expect($source)->not->toBeFalse();
    expect($source)->toContain('->withSchedule(');

    $closureBody = qwsExtractWithScheduleClosureBody($source);

    expect($closureBody)->not->toBeNull();

    $violations = qwsSchedulerOnOneServerViolations($closureBody);

    expect($violations)->toBe([], 'The following scheduled task(s) do not chain ->onOneServer(): '.implode(' | ', $violations));
})->group('arch');

/**
 * Proof that the discovery regex actually catches a violation — a guard
 * that has never been shown to fail is not a guard. Synthetic snippets, not
 * the real bootstrap/app.php file.
 */
test('qwsSchedulerOnOneServerViolations catches a scheduled task missing onOneServer()', function (): void {
    $compliant = "\$schedule->command('queue:prune-failed --hours=168')->dailyAt('03:10')->onOneServer();";
    $nonCompliant = "\$schedule->command('queue:prune-batches --hours=168')->dailyAt('03:20');";

    expect(qwsSchedulerOnOneServerViolations($compliant))->toBe([]);
    expect(qwsSchedulerOnOneServerViolations($nonCompliant))->toHaveCount(1);
    expect(qwsSchedulerOnOneServerViolations($compliant.' '.$nonCompliant))
        ->toHaveCount(1)
        ->and(qwsSchedulerOnOneServerViolations($compliant.' '.$nonCompliant)[0])->toContain('queue:prune-batches');
})->group('arch');
