<?php

declare(strict_types=1);

/**
 * tests/Unit/QueueRuntimeConfigTest.php — queue-worker-scheduler PR1/PR4.
 *
 * Tests App\Support\Queue\QueueRuntimeInvariant DIRECTLY — the same
 * production class `beai:queue-work --validate-only` executes. A previous
 * revision of this file duplicated the reflection walker locally instead of
 * exercising the production class; that meant `--validate-only` and this
 * test suite could silently diverge (confirmed the hard way in review: the
 * production class's floor-check block was deleted and the FULL suite,
 * including this file, stayed green — an actual removal of the defence,
 * undetected). There is now exactly ONE implementation of this logic.
 *
 * The invariant is `job $timeout < worker --timeout < retry_after`,
 * enforced with BOTH the ordering (Assertion A) AND a ceiling/floor
 * (Assertions B/C). Ordering alone is a loophole trivially satisfiable by
 * shrinking every number toward zero — Assertion C is the
 * config-independent floor that closes it.
 *
 * Assertion D (worker_queues well-formedness) and the QUEUE_WORKER_QUEUES
 * parsing tests at the bottom of this file close a separate silent-failure
 * path found in adversarial review: `QUEUE_WORKER_QUEUES=default, webhooks`
 * used to parse to ['default', ' webhooks'], and the worker then consumed
 * nothing at all from `webhooks` with no error signal.
 *
 * REQ: Timeout / Retry-After Ordering and Ceiling Invariant (queue-runtime/spec.md)
 */

use App\Jobs\ScoreEvaluationJob;
use App\Support\Queue\QueueRuntimeInvariant;
use Tests\Fixtures\QueueRuntimeInvariantFixtures\AllCompliant\ScoreLikeJob;
use Tests\Fixtures\QueueRuntimeInvariantFixtures\CeilingClassMissingTimeout\UncheckedJob;
use Tests\Fixtures\QueueRuntimeInvariantFixtures\MethodForm\TimeoutMethodJob;

// ─── Assertion A (ordering) — driven against the REAL app/ tree ──────────
// Real jobs (PR1) all declare $timeout, so config overrides alone are
// enough to drive both a green and a red ordering check without needing a
// fixture tree.

test('holds() is true and violations() is empty against the real app tree under default config', function (): void {
    $invariant = new QueueRuntimeInvariant;

    expect($invariant->violations())->toBe([])
        ->and($invariant->holds())->toBeTrue();
});

test('ordering violation: worker_timeout raised above retry_after is caught against the real app tree', function (): void {
    config()->set('queue.runtime.worker_timeout', 999999);

    $invariant = new QueueRuntimeInvariant;
    $violations = $invariant->violations();

    expect($violations)->not->toBeEmpty()
        ->and(implode(' ', $violations))->toContain('retry_after')
        ->and($invariant->holds())->toBeFalse();
});

test('ordering violation: worker_timeout dropped below the max declared job timeout is caught against the real app tree', function (): void {
    // A distinct ordering failure from the one above: here worker_timeout
    // itself still clears retry_after, but no longer clears the max
    // declared job timeout (ScoreEvaluationJob's 1200s).
    config()->set('queue.runtime.worker_timeout', 100);

    $invariant = new QueueRuntimeInvariant;
    $violations = $invariant->violations();

    expect(implode(' ', $violations))->toContain('max declared job timeout')
        ->and($invariant->holds())->toBeFalse();
});

// ─── Assertions B/C (ceiling + floor) — driven against the real ScoreEvaluationJob ──

test('ScoreEvaluationJob::$timeout clears the derived ceiling and the config-independent floor', function (): void {
    $invariant = new QueueRuntimeInvariant(ceilingCheckClass: ScoreEvaluationJob::class);

    $violations = $invariant->violations();
    $ceilingOrFloor = array_filter($violations, static fn (string $v): bool => str_contains($v, 'ScoreEvaluationJob'));

    expect($ceilingOrFloor)->toBe([]);
});

// ─── Every branch, driven against controlled fixture trees ───────────────

test('empty declared timeouts: "No ShouldQueue job declares a $timeout" branch', function (): void {
    $invariant = new QueueRuntimeInvariant(
        rootDir: base_path('tests/Fixtures/QueueRuntimeInvariantFixtures/Empty'),
        namespaceRoot: 'Tests\\Fixtures\\QueueRuntimeInvariantFixtures\\Empty',
    );

    expect($invariant->violations())->toBe(['No ShouldQueue job declares a $timeout.'])
        ->and($invariant->holds())->toBeFalse();
});

test('all-compliant fixture tree: violations() is empty', function (): void {
    $invariant = new QueueRuntimeInvariant(
        rootDir: base_path('tests/Fixtures/QueueRuntimeInvariantFixtures/AllCompliant'),
        namespaceRoot: 'Tests\\Fixtures\\QueueRuntimeInvariantFixtures\\AllCompliant',
        ceilingCheckClass: ScoreLikeJob::class,
    );

    expect($invariant->violations())->toBe([])
        ->and($invariant->holds())->toBeTrue();
});

test('ceiling violation: fixture job timeout is below the derived ceiling but above the floor', function (): void {
    $invariant = new QueueRuntimeInvariant(
        rootDir: base_path('tests/Fixtures/QueueRuntimeInvariantFixtures/CeilingViolation'),
        namespaceRoot: 'Tests\\Fixtures\\QueueRuntimeInvariantFixtures\\CeilingViolation',
        ceilingCheckClass: Tests\Fixtures\QueueRuntimeInvariantFixtures\CeilingViolation\ScoreLikeJob::class,
    );

    $violations = $invariant->violations();

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('derived ceiling')
        ->and($violations[0])->not->toContain('config-independent floor');
});

test('floor violation: fixture job timeout is below the 600s config-independent floor', function (): void {
    $invariant = new QueueRuntimeInvariant(
        rootDir: base_path('tests/Fixtures/QueueRuntimeInvariantFixtures/BelowFloor'),
        namespaceRoot: 'Tests\\Fixtures\\QueueRuntimeInvariantFixtures\\BelowFloor',
        ceilingCheckClass: Tests\Fixtures\QueueRuntimeInvariantFixtures\BelowFloor\ScoreLikeJob::class,
    );

    $violations = $invariant->violations();

    expect(implode(' ', $violations))->toContain('config-independent floor');
});

test('degenerate case: shrinking the anthropic timeout collapses the derived ceiling below the fixture timeout, but the floor still fails', function (): void {
    // Mirrors the exact scenario the floor exists to catch: with
    // scoring.anthropic.timeout_seconds shrunk to 10, the derived ceiling
    // collapses to 18 x 10 x 1.1 = 198s, which the 500s fixture timeout
    // clears (Assertion B alone would now PASS, formally "correct") — but
    // Assertion C (the literal 600s floor) still correctly fails.
    config()->set('scoring.anthropic.timeout_seconds', 10);

    $invariant = new QueueRuntimeInvariant(
        rootDir: base_path('tests/Fixtures/QueueRuntimeInvariantFixtures/BelowFloor'),
        namespaceRoot: 'Tests\\Fixtures\\QueueRuntimeInvariantFixtures\\BelowFloor',
        ceilingCheckClass: Tests\Fixtures\QueueRuntimeInvariantFixtures\BelowFloor\ScoreLikeJob::class,
    );

    $violations = $invariant->violations();

    expect(implode(' ', $violations))
        ->not->toContain('derived ceiling') // B passes — 500 >= 198
        ->toContain('config-independent floor'); // C still fails — 500 <= 600
});

test('ceiling-check class present in the tree but declares no $timeout of its own', function (): void {
    $invariant = new QueueRuntimeInvariant(
        rootDir: base_path('tests/Fixtures/QueueRuntimeInvariantFixtures/CeilingClassMissingTimeout'),
        namespaceRoot: 'Tests\\Fixtures\\QueueRuntimeInvariantFixtures\\CeilingClassMissingTimeout',
        ceilingCheckClass: UncheckedJob::class,
    );

    expect($invariant->violations())->toBe([
        'Tests\Fixtures\QueueRuntimeInvariantFixtures\CeilingClassMissingTimeout\UncheckedJob does not declare a $timeout — cannot verify the derived ceiling / config-independent floor.',
    ]);
});

test('discovery reads the timeout() METHOD form, not only the $timeout property', function (): void {
    $invariant = new QueueRuntimeInvariant(
        rootDir: base_path('tests/Fixtures/QueueRuntimeInvariantFixtures/MethodForm'),
        namespaceRoot: 'Tests\\Fixtures\\QueueRuntimeInvariantFixtures\\MethodForm',
        ceilingCheckClass: TimeoutMethodJob::class,
    );

    // 1200s clears both the default-config derived ceiling (1188s) and the
    // 600s floor — proves the method-form value (not a property default)
    // was actually read and fed into the same B/C checks.
    expect($invariant->violations())->toBe([]);
});

test('discovery guards against a timeout() method reading uninitialized constructor state', function (): void {
    // newInstanceWithoutConstructor() never runs UninitializedPropertyTimeoutJob's
    // constructor, so its timeout() method throws \Error accessing the
    // uninitialized readonly property. Without the try/catch guard in
    // discoverJobTimeouts(), this would crash violations() entirely instead
    // of degrading to "undeclared" for just this one job.
    $invariant = new QueueRuntimeInvariant(
        rootDir: base_path('tests/Fixtures/QueueRuntimeInvariantFixtures/UninitializedPropertyGuard'),
        namespaceRoot: 'Tests\\Fixtures\\QueueRuntimeInvariantFixtures\\UninitializedPropertyGuard',
    );

    expect(fn () => $invariant->violations())->not->toThrow(Throwable::class);
    expect($invariant->violations())->toBe(['No ShouldQueue job declares a $timeout.']);
});

// ─── QUEUE_WORKER_QUEUES parsing — config/queue.php, not config()->set ───
//
// These re-evaluate the REAL config/queue.php expression with a controlled
// env value, because the bug being guarded lives in the PARSING, not in any
// consumer. Driving them with config()->set() would assert on a value the
// production parser never produced and would stay green with the trim removed.

/**
 * Evaluate config/queue.php with QUEUE_WORKER_QUEUES temporarily set to
 * $value (null = unset, i.e. exercise the 'default' fallback) and return the
 * parsed queue.runtime.worker_queues list.
 *
 * $_SERVER/$_ENV are the sources Laravel's Env repository reads (ServerConst +
 * EnvConst adapters); both are restored exactly, including the "was not set at
 * all" case, so no later test in this worker process inherits the override.
 *
 * @return array<int, mixed>
 */
function qwsParseWorkerQueuesEnv(?string $value): array
{
    $hadServer = array_key_exists('QUEUE_WORKER_QUEUES', $_SERVER);
    $hadEnv = array_key_exists('QUEUE_WORKER_QUEUES', $_ENV);
    $previousServer = $_SERVER['QUEUE_WORKER_QUEUES'] ?? null;
    $previousEnv = $_ENV['QUEUE_WORKER_QUEUES'] ?? null;

    try {
        if ($value === null) {
            unset($_SERVER['QUEUE_WORKER_QUEUES'], $_ENV['QUEUE_WORKER_QUEUES']);
        } else {
            $_SERVER['QUEUE_WORKER_QUEUES'] = $value;
            $_ENV['QUEUE_WORKER_QUEUES'] = $value;
        }

        /** @var array{runtime: array{worker_queues: array<int, mixed>}} $config */
        $config = require config_path('queue.php');

        return $config['runtime']['worker_queues'];
    } finally {
        if ($hadServer) {
            $_SERVER['QUEUE_WORKER_QUEUES'] = $previousServer;
        } else {
            unset($_SERVER['QUEUE_WORKER_QUEUES']);
        }

        if ($hadEnv) {
            $_ENV['QUEUE_WORKER_QUEUES'] = $previousEnv;
        } else {
            unset($_ENV['QUEUE_WORKER_QUEUES']);
        }
    }
}

test('QUEUE_WORKER_QUEUES trims whitespace around each entry', function (): void {
    // The exact operator typo from the finding: a space after the comma is an
    // entirely normal way to write a comma-separated env var. Untrimmed, this
    // produced [' webhooks'], forwarded as `--queue=default, webhooks`, and the
    // worker silently consumed NOTHING from the webhooks queue.
    expect(qwsParseWorkerQueuesEnv('default, webhooks'))->toBe(['default', 'webhooks']);
})->group('queue');

test('QUEUE_WORKER_QUEUES trims leading, trailing and tab whitespace on every entry', function (): void {
    expect(qwsParseWorkerQueuesEnv("  default ,\twebhooks\t, scoring  "))
        ->toBe(['default', 'webhooks', 'scoring']);
})->group('queue');

test('QUEUE_WORKER_QUEUES drops empty entries and reindexes the list', function (): void {
    // A trailing comma / double comma must not produce '' entries: `--queue`
    // is a comma-joined string, so an empty entry means an empty queue name.
    // array_values() matters — an un-reindexed array json/implodes differently
    // and is not the list<string> the invariant and the wrapper expect.
    expect(qwsParseWorkerQueuesEnv('default,,webhooks,'))->toBe(['default', 'webhooks']);
})->group('queue');

test('QUEUE_WORKER_QUEUES unset falls back to the single default queue', function (): void {
    expect(qwsParseWorkerQueuesEnv(null))->toBe(['default']);
})->group('queue');

test('QUEUE_WORKER_QUEUES made entirely of separators and whitespace parses to an empty list, which Assertion D then rejects', function (): void {
    // Trimming alone CANNOT signal this case — it degrades to an empty list,
    // `--queue=''`, and WorkCommand::getQueue()'s `?:` silently falls back to
    // the connection default queue. This is why the invariant validates it.
    $parsed = qwsParseWorkerQueuesEnv(' , , ');

    expect($parsed)->toBe([]);

    config()->set('queue.runtime.worker_queues', $parsed);

    expect(implode(' ', (new QueueRuntimeInvariant)->violations()))
        ->toContain('must be a non-empty list of queue names');
})->group('queue');

// ─── Assertion D — worker_queues well-formedness ─────────────────────────

test('Assertion D: an untrimmed queue name is reported as a violation', function (): void {
    config()->set('queue.runtime.worker_queues', ['default', ' webhooks']);

    $invariant = new QueueRuntimeInvariant;
    $violations = $invariant->violations();

    expect(implode(' ', $violations))->toContain('contains whitespace')
        ->and(implode(' ', $violations))->toContain('webhooks')
        ->and($invariant->holds())->toBeFalse();
})->group('queue');

test('Assertion D: an empty-string queue name is reported as a violation', function (): void {
    config()->set('queue.runtime.worker_queues', ['default', '']);

    expect(implode(' ', (new QueueRuntimeInvariant)->violations()))
        ->toContain('empty or whitespace-only queue name');
})->group('queue');

test('Assertion D: a comma inside a queue name is reported as a violation', function (): void {
    // Entries are comma-JOINED into --queue, so an embedded comma silently
    // becomes two queue names.
    config()->set('queue.runtime.worker_queues', ['default,webhooks']);

    expect(implode(' ', (new QueueRuntimeInvariant)->violations()))
        ->toContain('contains a comma');
})->group('queue');

test('Assertion D: a non-string entry is reported as a violation', function (): void {
    config()->set('queue.runtime.worker_queues', ['default', 42]);

    expect(implode(' ', (new QueueRuntimeInvariant)->violations()))
        ->toContain('non-string entry (int)');
})->group('queue');

test('Assertion D: legitimate queue names with hyphens, underscores and colons are NOT flagged', function (): void {
    // Guards the opposite failure: over-constraining queue names would break
    // real deployments. Laravel queue names are arbitrary strings.
    config()->set('queue.runtime.worker_queues', ['default', 'high-priority', 'emails:v2', 'tenant_42']);

    expect((new QueueRuntimeInvariant)->violations())->toBe([]);
})->group('queue');
