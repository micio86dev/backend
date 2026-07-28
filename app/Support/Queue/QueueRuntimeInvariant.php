<?php

declare(strict_types=1);

namespace App\Support\Queue;

use App\Jobs\ScoreEvaluationJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Production-code counterpart to PR1's tests/Unit/QueueRuntimeConfigTest.php
 * — the SAME three assertions (A: ordering, B: derived ceiling, C:
 * config-independent floor), evaluated against LIVE config so
 * `beai:queue-work --validate-only` can fail fast at container startup
 * instead of drifting into a bad configuration at runtime
 * (queue-runtime/spec.md Requirement 2).
 *
 * Deliberately duplicates PR1's reflection walker rather than importing the
 * test file (test files are not autoloaded in production) — mirrors this
 * codebase's existing convention of duplicating discovery walkers per use
 * site (see tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php vs
 * tests/Arch/Queue/QueuedJobRetryOwnershipArchTest.php).
 */
final class QueueRuntimeInvariant
{
    private const MAX_ROLE_COMPETENCIES = 18; // CLAUDE.md — max competencies per role.

    private const CONFIG_INDEPENDENT_FLOOR_SECONDS = 600;

    /**
     * @return list<string> human-readable violation messages; empty = invariant holds.
     */
    public function violations(): array
    {
        $violations = [];

        $timeouts = $this->discoverJobTimeouts(app_path(), 'App');
        $declared = array_filter($timeouts, static fn (?int $timeout): bool => $timeout !== null);

        if ($declared === []) {
            $violations[] = 'No ShouldQueue job declares a $timeout.';

            return $violations;
        }

        $maxJobTimeout = max($declared);
        $workerTimeout = (int) config('queue.runtime.worker_timeout');
        $redisRetryAfter = (int) config('queue.connections.redis.retry_after');
        $databaseRetryAfter = (int) config('queue.connections.database.retry_after');

        if ($maxJobTimeout >= $workerTimeout) {
            $violations[] = "max declared job timeout ({$maxJobTimeout}s) must be < queue.runtime.worker_timeout ({$workerTimeout}s)";
        }

        if ($workerTimeout >= $redisRetryAfter) {
            $violations[] = "queue.runtime.worker_timeout ({$workerTimeout}s) must be < connections.redis.retry_after ({$redisRetryAfter}s)";
        }

        if ($workerTimeout >= $databaseRetryAfter) {
            $violations[] = "queue.runtime.worker_timeout ({$workerTimeout}s) must be < connections.database.retry_after ({$databaseRetryAfter}s)";
        }

        $scoreEvaluationTimeout = $timeouts[ScoreEvaluationJob::class] ?? null;

        if ($scoreEvaluationTimeout !== null) {
            $anthropicTimeout = (int) config('scoring.anthropic.timeout_seconds');
            $derivedCeiling = (int) ceil(self::MAX_ROLE_COMPETENCIES * $anthropicTimeout * 1.1);

            if ($scoreEvaluationTimeout < $derivedCeiling) {
                $violations[] = "ScoreEvaluationJob::\$timeout ({$scoreEvaluationTimeout}s) must be >= the derived ceiling ({$derivedCeiling}s)";
            }

            if ($scoreEvaluationTimeout <= self::CONFIG_INDEPENDENT_FLOOR_SECONDS) {
                $violations[] = "ScoreEvaluationJob::\$timeout ({$scoreEvaluationTimeout}s) must exceed the ".self::CONFIG_INDEPENDENT_FLOOR_SECONDS.'s config-independent floor';
            }
        } else {
            $violations[] = 'ScoreEvaluationJob does not declare a $timeout — cannot verify the derived ceiling / config-independent floor.';
        }

        return $violations;
    }

    public function holds(): bool
    {
        return $this->violations() === [];
    }

    /**
     * Recursively scan $rootDir for ShouldQueue implementors and return each
     * class's declared execution timeout in seconds (property `$timeout` or
     * method `timeout()`). A class that declares neither maps to null.
     *
     * @return array<class-string, int|null>
     */
    private function discoverJobTimeouts(string $rootDir, string $namespaceRoot): array
    {
        if (! is_dir($rootDir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );

        $timeouts = [];

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
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

            $reflection = new \ReflectionClass($class);

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
}
