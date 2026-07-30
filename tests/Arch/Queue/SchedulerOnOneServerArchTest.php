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
 * Operates on a raw PHP-source STRING rather than a class tree — the object
 * under guard here is a single closure body, not a directory of classes —
 * but keeps PR1's "prove it can fail before trusting it" discipline, and
 * keeps its positive proof in a permanent, controlled FIXTURE FILE
 * (tests/Fixtures/ArchGuardFixtures/SchedulerBootstrap/NonCompliantScheduleBootstrapFixture.php),
 * never by mutating the real bootstrap/app.php. That fixture drives the
 * extractor and the scanner TOGETHER, so the end-to-end pipeline — not just
 * its second half — is the thing proven capable of failing.
 *
 * REQ: Scheduler Pinned to a Single Active Replica (queue-runtime/spec.md)
 */

/**
 * Every `Illuminate\Console\Scheduling\Schedule` entry point that registers a
 * NEW scheduled task, and therefore every entry point this guard must see.
 *
 * ALL FOUR, not just command/call: `->job()` (queue a job class on a
 * schedule) and `->exec()` (run a shell command on a schedule) are equally
 * ordinary Schedule methods, and an earlier revision of this guard
 * recognised only `command`/`call` — a `$schedule->job(new FinalizeInterview(1))->daily();`
 * with no ->onOneServer() was completely invisible to it (adversarial-review
 * finding, reproduced against the real bootstrap/app.php: the arch test
 * stayed green). That blind spot mattered specifically because a
 * job-class-based scheduled task is a completely ordinary way to implement
 * C13's GDPR purge sweep — the very "first real consumer" this guard's own
 * docblock anticipates.
 *
 * Anything NOT in this list is deliberately out of scope: `$schedule->`
 * alone could match a non-registering accessor (e.g. a hypothetical
 * $schedule->timezone(...)) that has no ->onOneServer() concept to begin
 * with. Adding a Schedule entry point here is the single edit needed to
 * extend coverage — see the fixture-driven proof tests below.
 */
const QWS_SCHEDULE_ENTRY_POINT_PATTERN = '/^\$schedule->(?:command|call|job|exec)\(/';

/**
 * Scan $closureSource (the raw text of a ->withSchedule() closure body) for
 * every scheduling chain (QWS_SCHEDULE_ENTRY_POINT_PATTERN — command / call /
 * job / exec) and return the raw text of any chain that does NOT contain
 * `onOneServer(`.
 *
 * A chain's true end is a `;` at bracket depth 0 — tracking `()`, `{}`, and
 * `[]` together, NOT a naive "first semicolon" scan. A `->call(function () {
 * ...; })` closure body legitimately contains its own semicolons before the
 * chain's real terminator; a naive `[^;]*;` regex would stop at the FIRST
 * one and truncate the chain before ever reaching a trailing
 * `->onOneServer()`, producing a false violation (readability-review
 * finding — see the nested-semicolon proof test below).
 *
 * @param  string  $entryPointPattern  Defaults to the real
 *                                     QWS_SCHEDULE_ENTRY_POINT_PATTERN. Overridable ONLY so the
 *                                     fail-loud proof test below can drive the PCRE-failure branch
 *                                     deterministically — same rationale as
 *                                     App\Support\Queue\QueueRuntimeInvariant's constructor
 *                                     parameters (a test seam beats a second, divergable copy of
 *                                     the logic). No production caller ever passes it.
 * @return list<string>
 *
 * @throws RuntimeException when the PCRE engine itself fails (see below).
 */
function qwsSchedulerOnOneServerViolations(string $closureSource, string $entryPointPattern = QWS_SCHEDULE_ENTRY_POINT_PATTERN): array
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

        // Only chains that actually START with a scheduling entry point are
        // in scope (see QWS_SCHEDULE_ENTRY_POINT_PATTERN above).
        $isScheduledTask = preg_match($entryPointPattern, $chain);

        // FAIL LOUDLY, never open. preg_match() returns `false` — not 0 — on
        // an internal PCRE failure (backtrack/recursion limit exhausted,
        // bad UTF-8 subject). An earlier revision tested `=== 1` / `=== false`
        // in a way that folded that `false` into "not a scheduled task", so a
        // regex-engine failure made this SAFETY GUARD report ZERO violations
        // and pass green. For a guard, "I could not tell" must be an error,
        // not an all-clear (adversarial-review finding — see the PCRE-failure
        // proof test below, which reproduces it for real via pcre.backtrack_limit).
        if ($isScheduledTask === false) {
            throw new RuntimeException(
                'PCRE failure while classifying a scheduled-task chain: '.preg_last_error_msg()
                .'. Refusing to report "no violations" on an engine failure. Chain: '.trim($chain)
            );
        }

        if ($isScheduledTask === 1 && ! str_contains($chain, 'onOneServer(')) {
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

/**
 * Adversarial-review finding — the guard recognised only `command`/`call`, so
 * `$schedule->job(...)` and `$schedule->exec(...)` (equally ordinary Schedule
 * entry points) were COMPLETELY INVISIBLE to it: injecting a non-onOneServer
 * ->job() into the real bootstrap/app.php left this arch test green.
 *
 * Drives the FULL pipeline — qwsExtractWithScheduleClosureBody() AND
 * qwsSchedulerOnOneServerViolations() — against a controlled fixture FILE, so
 * the extractor is proven too (the snippet tests above only ever exercised the
 * scanner). The fixture is permanent and never the real bootstrap/app.php.
 */
test('qwsSchedulerOnOneServerViolations catches non-onOneServer ->job() and ->exec() registrations in a fixture bootstrap source', function (): void {
    $fixture = base_path('tests/Fixtures/ArchGuardFixtures/SchedulerBootstrap/NonCompliantScheduleBootstrapFixture.php');
    $source = file_get_contents($fixture);

    expect($source)->not->toBeFalse();

    $closureBody = qwsExtractWithScheduleClosureBody($source);

    expect($closureBody)->not->toBeNull();

    $violations = qwsSchedulerOnOneServerViolations($closureBody);
    $reported = implode(' | ', $violations);

    // All four entry points are recognised: the three non-compliant
    // registrations are reported, each identified by its own distinctive text.
    expect($violations)->toHaveCount(3)
        ->and($reported)->toContain('queue:prune-failed')      // command() violation
        ->and($reported)->toContain('FinalizeInterview(1)')    // job() violation
        ->and($reported)->toContain('php artisan inspire');    // exec() violation

    // ...and the two COMPLIANT registrations are not: recognising more entry
    // points must not turn the guard into a false-positive generator.
    expect($reported)->not->toContain('everyMinute')           // compliant call()
        ->and($reported)->not->toContain('FinalizeInterview(2)'); // compliant job()
})->group('arch');

/**
 * Adversarial-review finding — the PCRE classification call FAILED OPEN.
 * preg_match() returns `false` (not 0) on an internal engine failure, and the
 * previous `=== 1` / `=== false` handling folded that into "not a scheduled
 * task", so an engine failure made this safety guard report ZERO violations
 * and pass green. Wrong failure direction for a guard.
 *
 * Being honest about what is provable: the PRODUCTION pattern is `^`-anchored,
 * linear and non-unicode, so it realistically cannot fail — the branch guards
 * FUTURE pattern changes (adding /u for a unicode-aware entry point, or a
 * costlier alternation) far more than today's. That is precisely why it needs
 * the $entryPointPattern seam to be provable at all, and why "unreachable
 * today" is not a reason to leave it failing open.
 *
 * The seam adds exactly ONE character to the real pattern — the `u` modifier —
 * and feeds a chain containing invalid UTF-8. preg_match() then returns false
 * with PREG_BAD_UTF8_ERROR, deterministically and instantly, independent of
 * pcre.jit and of test execution order.
 *
 * Two other routes were tried and REJECTED as unreliable:
 *   - lowering pcre.backtrack_limit at runtime: PHP caches COMPILED patterns,
 *     so once the real pattern has been JIT-compiled by an earlier test in the
 *     same process, toggling pcre.jit/pcre.backtrack_limit no longer affects
 *     it — the test then passed or failed depending on execution order, which
 *     is worse than no test at all (observed, not theorised).
 *   - a catastrophic-backtracking pattern: PCRE2's auto-possessification and
 *     required-character optimisations collapsed every candidate to a plain
 *     0-result before any backtracking happened.
 */
test('qwsSchedulerOnOneServerViolations throws instead of reporting zero violations when PCRE itself fails', function (): void {
    // Invalid UTF-8 (\xFF\xFE) inside an otherwise well-formed, NON-COMPLIANT chain.
    $nonCompliant = "\$schedule->command('\xFF\xFE')->dailyAt('03:20');";

    // Sanity: under the real (non-unicode) pattern this subject is healthy AND
    // is a genuine violation. Without this, the "throws" assertion below could
    // pass for the wrong reason.
    expect(qwsSchedulerOnOneServerViolations($nonCompliant))->toHaveCount(1);

    $unicodeAwarePattern = QWS_SCHEDULE_ENTRY_POINT_PATTERN.'u';

    // A fail-open guard returns [] here — a silent all-clear on a real
    // violation. It must throw instead.
    expect(fn (): array => qwsSchedulerOnOneServerViolations($nonCompliant, $unicodeAwarePattern))
        ->toThrow(RuntimeException::class, 'Malformed UTF-8');
})->group('arch');
