<?php

declare(strict_types=1);

/**
 * Architecture guard: every scheduled task registered inside the
 * ->withSchedule() closure in api/bootstrap/app.php MUST chain
 * ->onOneServer() (design.md D6, queue-runtime/spec.md Requirement 7 —
 * "Scheduler Pinned to a Single Active Replica").
 *
 * PR2 registered the scheduler RUNNER with an empty closure. PR3 folded in
 * the first real tasks (queue:prune-failed, queue:prune-batches), so this
 * guard now protects real production input, not only the synthetic
 * snippets below — confirmed directly: inverting the detection condition
 * against the CURRENT bootstrap/app.php (both real prune tasks registered)
 * makes the real-code test fail, naming both tasks. `deploy.replicas: 1`
 * (docker-compose, wrapper PR5) is overridable by --scale, so onOneServer()
 * (backed by a real DB lock — Illuminate\Cache\DatabaseStore implements
 * LockProvider, cache_locks table already exists) is the structural
 * defense-in-depth against a double-run, not just documentation.
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
 * every `$schedule->command(...)` / `$schedule->call(...)` chain and return
 * the raw text of any chain that does NOT contain `onOneServer(`.
 *
 * A chain's true end is a `;` at bracket depth 0 — tracking `()`, `{}`, and
 * `[]` together, NOT a naive "first semicolon" scan. A `->call(function () {
 * ...; })` closure body legitimately contains its own semicolons before the
 * chain's real terminator; a naive `[^;]*;` regex would stop at the FIRST
 * one and truncate the chain before ever reaching a trailing
 * `->onOneServer()`, producing a false violation (readability-review
 * finding — see the nested-semicolon proof test below).
 *
 * @return list<string>
 */
function qwsSchedulerOnOneServerViolations(string $closureSource): array
{
    $violations = [];
    $length = strlen($closureSource);
    $offset = 0;

    while (($start = strpos($closureSource, '$schedule->', $offset)) !== false) {
        $depth = 0;
        $end = null;

        for ($i = $start; $i < $length; $i++) {
            $char = $closureSource[$i];

            if ($char === '(' || $char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === '}' || $char === ']') {
                $depth--;
            } elseif ($char === ';' && $depth === 0) {
                $end = $i;
                break;
            }
        }

        if ($end === null) {
            // Unterminated statement (malformed/truncated input) — stop
            // scanning rather than looping forever or misattributing text
            // past the end of a real statement.
            break;
        }

        $chain = substr($closureSource, $start, $end - $start + 1);

        // Only chains that actually START with a scheduling call are in
        // scope — `$schedule->` alone could match a future accessor
        // (e.g. a hypothetical $schedule->timezone(...)) that isn't one of
        // these two and has no ->onOneServer() concept to begin with.
        if (preg_match('/^\$schedule->(?:command|call)\(/', $chain) === 1 && ! str_contains($chain, 'onOneServer(')) {
            $violations[] = trim($chain);
        }

        $offset = $end + 1;
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

/**
 * Readability-review finding: a naive `[^;]*;` regex stops at the FIRST
 * semicolon, so a closure body containing an internal `;` (e.g. a
 * `->call(function () { ...; })`) would be truncated before the regex ever
 * reaches a legitimate trailing `->onOneServer()`, producing a FALSE
 * violation. This proves the chain-boundary scanner is nesting-aware
 * (parens/braces/brackets), not just semicolon-aware.
 */
test('qwsSchedulerOnOneServerViolations does not truncate at a semicolon nested inside a ->call() closure body', function (): void {
    $compliantWithInternalSemicolon = "\$schedule->call(function () {\n    \\Illuminate\\Support\\Facades\\Log::info('tick');\n})->everyMinute()->onOneServer();";

    expect(qwsSchedulerOnOneServerViolations($compliantWithInternalSemicolon))->toBe([]);
})->group('arch');
