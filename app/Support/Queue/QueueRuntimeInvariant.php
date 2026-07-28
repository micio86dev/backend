<?php

declare(strict_types=1);

namespace App\Support\Queue;

use App\Jobs\ScoreEvaluationJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Single source of truth for the timeout/retry_after ordering-and-ceiling
 * invariant (queue-runtime/spec.md Requirement 2). Three assertions:
 * A: max(declared job timeout) < worker_timeout < connections.*.retry_after.
 * B: the ceiling-check job's timeout clears the derived ceiling
 *    (MAX_ROLE_COMPETENCIES x scoring.anthropic.timeout_seconds x 1.1).
 * C: the ceiling-check job's timeout independently exceeds a
 *    config-independent floor — the anti-degenerate lock: A+B alone are
 *    satisfiable by shrinking every number toward zero (e.g. dropping
 *    scoring.anthropic.timeout_seconds collapses B's derived ceiling too).
 *
 * Used DIRECTLY by both `beai:queue-work --validate-only`
 * (App\Console\Commands\QueueWorkCommand) so the container can fail fast at
 * startup, AND by tests/Unit/QueueRuntimeConfigTest.php. There is
 * deliberately only ONE implementation of this logic — an earlier revision
 * duplicated the reflection walker into the test file, which meant the two
 * copies could silently diverge (e.g. change the 1.1 multiplier or the 600s
 * floor in one place and the OTHER copy keeps passing) while looking
 * identically green. Constructor parameters exist so tests can point the
 * scan at a controlled fixture tree instead of the real app/ directory,
 * without needing a second implementation to do it.
 */
final class QueueRuntimeInvariant
{
    /**
     * Max competencies per role (CLAUDE.md — SRX/FLL/MLL). Single source of
     * truth for the derived-ceiling multiplier: app/Jobs/ScoreEvaluationJob.php's
     * docblock and config/queue.php's docblock both point here rather than
     * restating the literal, so there is exactly one place this number can
     * drift from.
     */
    public const MAX_ROLE_COMPETENCIES = 18;

    /**
     * Config-independent floor in seconds — a LITERAL, not derived from any
     * config value. This is what Assertion C guards with.
     */
    public const CONFIG_INDEPENDENT_FLOOR_SECONDS = 600;

    private readonly string $rootDir;

    private readonly string $namespaceRoot;

    /** @var class-string */
    private readonly string $ceilingCheckClass;

    /**
     * @param  string|null  $rootDir  Directory to recursively scan for
     *                                ShouldQueue implementors. Defaults to app_path() in production;
     *                                tests may point this at a controlled fixture tree.
     * @param  string|null  $namespaceRoot  PSR-4 namespace root matching
     *                                      $rootDir. Defaults to 'App'.
     * @param  class-string|null  $ceilingCheckClass  The job class whose
     *                                                timeout is checked against the derived ceiling and the
     *                                                config-independent floor (Assertions B/C). Defaults to
     *                                                ScoreEvaluationJob in production — the only job in this
     *                                                codebase whose execution time scales with a variable
     *                                                (competency count x LLM call latency).
     */
    public function __construct(
        ?string $rootDir = null,
        ?string $namespaceRoot = null,
        ?string $ceilingCheckClass = null,
    ) {
        $this->rootDir = $rootDir ?? app_path();
        $this->namespaceRoot = $namespaceRoot ?? 'App';
        $this->ceilingCheckClass = $ceilingCheckClass ?? ScoreEvaluationJob::class;
    }

    /**
     * @return list<string> human-readable violation messages; empty = invariant holds.
     */
    public function violations(): array
    {
        $violations = [];

        $timeouts = $this->discoverJobTimeouts($this->rootDir, $this->namespaceRoot);
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

        $ceilingCheckTimeout = $timeouts[$this->ceilingCheckClass] ?? null;

        if ($ceilingCheckTimeout !== null) {
            $anthropicTimeout = (int) config('scoring.anthropic.timeout_seconds');
            $derivedCeiling = (int) ceil(self::MAX_ROLE_COMPETENCIES * $anthropicTimeout * 1.1);

            if ($ceilingCheckTimeout < $derivedCeiling) {
                $violations[] = "{$this->ceilingCheckClass}::\$timeout ({$ceilingCheckTimeout}s) must be >= the derived ceiling ({$derivedCeiling}s)";
            }

            // Assertion C — config-independent floor. Deliberately a SEPARATE
            // `if`, not an `elseif` off the ceiling check above: both must be
            // evaluated independently so shrinking scoring.anthropic.timeout_seconds
            // (which shrinks $derivedCeiling) cannot silently satisfy B while
            // still being caught by C.
            if ($ceilingCheckTimeout <= self::CONFIG_INDEPENDENT_FLOOR_SECONDS) {
                $violations[] = "{$this->ceilingCheckClass}::\$timeout ({$ceilingCheckTimeout}s) must exceed the ".self::CONFIG_INDEPENDENT_FLOOR_SECONDS.'s config-independent floor';
            }
        } else {
            $violations[] = "{$this->ceilingCheckClass} does not declare a \$timeout — cannot verify the derived ceiling / config-independent floor.";
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

                    try {
                        // newInstanceWithoutConstructor() never ran the real
                        // constructor, so any typed/readonly property a
                        // future job's timeout() reads from constructor
                        // state is uninitialized here and would throw
                        // \Error on access. Treat that as "undeclared"
                        // rather than crashing --validate-only or the
                        // health probe over a job whose timeout() simply
                        // cannot be evaluated outside a real dispatch.
                        $timeouts[$class] = (int) $method->invoke($instance);
                    } catch (\Throwable) {
                        $timeouts[$class] = null;
                    }

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
