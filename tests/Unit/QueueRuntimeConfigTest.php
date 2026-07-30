<?php

declare(strict_types=1);

/**
 * RED — 2.1: config/queue.php runtime invariant (queue-worker-scheduler PR1,
 * design.md D2, queue-runtime/spec.md Requirement 2).
 *
 * The invariant is `job $timeout < worker --timeout < retry_after`, enforced
 * with BOTH the ordering (Assertion A) AND a ceiling (Assertions B/C).
 * Ordering alone is a loophole trivially satisfiable by shrinking every
 * number toward zero — Assertion C is the config-independent floor that
 * closes it: dropping `scoring.anthropic.timeout_seconds` from 60 to 10
 * would make Assertion B collapse to 198s while staying formally "correct".
 *
 * Model: tests/Unit/C10/WebhooksConfigTest.php:16-26 (config-invariant shape).
 * Discovery: recursive ShouldQueue walk, same technique as
 * tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php:44-95, but collecting
 * declared $timeout instead of checking for TenantContextScope::.
 *
 * REQ: Timeout / Retry-After Ordering and Ceiling Invariant (queue-runtime/spec.md)
 */

use App\Jobs\ScoreEvaluationJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Recursively scan $rootDir for ShouldQueue implementors and return each
 * class's declared execution timeout in seconds (property `$timeout` or
 * method `timeout()`). A class that declares neither maps to null — the
 * caller decides how to treat an undeclared timeout.
 *
 * @return array<class-string, int|null>
 */
function qwsDiscoverJobTimeouts(string $rootDir, string $namespaceRoot): array
{
    if (! is_dir($rootDir)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );

    $timeouts = [];

    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
            continue;
        }

        $relativePath = ltrim(
            str_replace($rootDir, '', $fileInfo->getPathname()),
            DIRECTORY_SEPARATOR
        );
        $relativeClass = str_replace(
            ['/', DIRECTORY_SEPARATOR, '.php'],
            ['\\', '\\', ''],
            $relativePath
        );
        $class = rtrim($namespaceRoot, '\\').'\\'.$relativeClass;

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        if ($reflection->hasMethod('timeout')) {
            $method = $reflection->getMethod('timeout');

            if ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0 && $method->getDeclaringClass()->getName() === $class) {
                $instance = $reflection->newInstanceWithoutConstructor();
                $timeouts[$class] = (int) $method->invoke($instance);

                continue;
            }
        }

        if ($reflection->hasProperty('timeout') && $reflection->getProperty('timeout')->getDeclaringClass()->getName() === $class) {
            $property = $reflection->getProperty('timeout');
            $property->setAccessible(true);
            $default = $property->getDefaultValue();
            $timeouts[$class] = $default !== null ? (int) $default : null;

            continue;
        }

        $timeouts[$class] = null;
    }

    return $timeouts;
}

test('every declared job timeout is less than the worker timeout, which is less than retry_after on every connection', function (): void {
    $timeouts = qwsDiscoverJobTimeouts(app_path(), 'App');
    $declared = array_filter($timeouts, static fn (?int $timeout): bool => $timeout !== null);

    expect($declared)->not->toBeEmpty(
        'No ShouldQueue job declares a $timeout — every queued job must declare its own execution ceiling.'
    );

    $maxJobTimeout = max($declared);
    $workerTimeout = (int) config('queue.runtime.worker_timeout');
    $redisRetryAfter = (int) config('queue.connections.redis.retry_after');
    $databaseRetryAfter = (int) config('queue.connections.database.retry_after');

    expect($maxJobTimeout)->toBeLessThan(
        $workerTimeout,
        "max declared job timeout ({$maxJobTimeout}s) must be < queue.runtime.worker_timeout ({$workerTimeout}s)"
    );

    expect($workerTimeout)->toBeLessThan(
        $redisRetryAfter,
        "queue.runtime.worker_timeout ({$workerTimeout}s) must be < connections.redis.retry_after ({$redisRetryAfter}s)"
    );

    expect($workerTimeout)->toBeLessThan(
        $databaseRetryAfter,
        "queue.runtime.worker_timeout ({$workerTimeout}s) must be < connections.database.retry_after ({$databaseRetryAfter}s)"
    );
})->group('arch');

test('ScoreEvaluationJob timeout clears the derived ceiling: 18 competencies x anthropic timeout x 1.1', function (): void {
    $reflection = new ReflectionClass(ScoreEvaluationJob::class);

    expect($reflection->hasProperty('timeout'))->toBeTrue(
        'ScoreEvaluationJob must declare a $timeout property.'
    );

    $property = $reflection->getProperty('timeout');
    $property->setAccessible(true);
    $declaredTimeout = (int) $property->getDefaultValue();

    $maxRoleCompetencies = 18; // CLAUDE.md — max competencies per role (SRX/FLL/MLL).
    $anthropicTimeout = (int) config('scoring.anthropic.timeout_seconds');
    $derivedCeiling = (int) ceil($maxRoleCompetencies * $anthropicTimeout * 1.1);

    expect($declaredTimeout)->toBeGreaterThanOrEqual(
        $derivedCeiling,
        "ScoreEvaluationJob::\$timeout ({$declaredTimeout}s) must be >= the derived ceiling ({$derivedCeiling}s = {$maxRoleCompetencies} x {$anthropicTimeout}s x 1.1)"
    );
});

test('ScoreEvaluationJob timeout independently exceeds the config-independent 600s floor', function (): void {
    $reflection = new ReflectionClass(ScoreEvaluationJob::class);

    expect($reflection->hasProperty('timeout'))->toBeTrue(
        'ScoreEvaluationJob must declare a $timeout property.'
    );

    $property = $reflection->getProperty('timeout');
    $property->setAccessible(true);
    $declaredTimeout = (int) $property->getDefaultValue();

    // Literal 600 — NOT derived from config. This is the anti-degenerate lock:
    // the derived-ceiling assertion above alone is satisfiable by shrinking
    // scoring.anthropic.timeout_seconds toward zero (e.g. 10s -> ceiling 198s).
    expect($declaredTimeout)->toBeGreaterThan(
        600,
        "ScoreEvaluationJob::\$timeout ({$declaredTimeout}s) must exceed the 600s config-independent floor"
    );
});
