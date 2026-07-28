<?php

declare(strict_types=1);

/**
 * Architecture guard: every ShouldQueue job MUST declare its own retry
 * ownership — $tries/tries() AND $timeout/timeout() — never inherit a
 * worker-level default silently.
 *
 * This is the structural half of queue-runtime/spec.md Requirement 4
 * ("Job-Level Retry Ownership"): a worker-level `--tries` would override
 * each job's own retry policy — DeliverWebhookJob owns a 6-attempt state
 * machine with its own `pending -> dead` transition (see
 * app/Jobs/DeliverWebhookJob.php class doc), and a framework cap would
 * dead-letter it early, silently rewriting C10's design. PR1's job is this
 * arch test; the structural enforcement (a `beai:queue-work` wrapper that
 * never defines `--tries` at all) is PR2.
 *
 * Mirrors the reflection+glob+violations shape of
 * tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php:44-95 (cloned, not
 * shared — same convention as that file).
 *
 * REQ: Job-Level Retry Ownership + Every Queued Job Declares Its Own Timeout
 * (queue-runtime/spec.md)
 */

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Recursively scan $rootDir for .php files, resolve each to a fully-qualified
 * class name under $namespaceRoot (PSR-4: directory structure mirrors
 * namespace), and return the class names of every ShouldQueue implementor
 * that does NOT declare its own retry attempts ($tries or tries()) AND its
 * own timeout ($timeout or timeout()) — unless allowlisted.
 *
 * @param  array<string, string>  $allowlist  class-string => written justification
 * @return list<string>
 */
function qwsDiscoverRetryOwnershipViolations(string $rootDir, string $namespaceRoot, array $allowlist): array
{
    if (! is_dir($rootDir)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );

    $violations = [];

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

        if (array_key_exists($class, $allowlist)) {
            continue;
        }

        $declaresTries = ($reflection->hasProperty('tries') && $reflection->getProperty('tries')->getDeclaringClass()->getName() === $class)
            || ($reflection->hasMethod('tries') && $reflection->getMethod('tries')->getDeclaringClass()->getName() === $class);

        $declaresTimeout = ($reflection->hasProperty('timeout') && $reflection->getProperty('timeout')->getDeclaringClass()->getName() === $class)
            || ($reflection->hasMethod('timeout') && $reflection->getMethod('timeout')->getDeclaringClass()->getName() === $class);

        if (! $declaresTries || ! $declaresTimeout) {
            $violations[] = $class;
        }
    }

    return $violations;
}

test('every ShouldQueue job under app/ declares its own $tries/tries() AND $timeout/timeout()', function (): void {
    $violations = qwsDiscoverRetryOwnershipViolations(app_path(), 'App', []);

    expect($violations)
        ->toBe([], 'The following ShouldQueue jobs do not declare BOTH their own retry attempts and their own '
            .'timeout: '.implode(', ', $violations));
})->group('arch');

/**
 * Proof that the recursive discovery actually works — a guard that has never
 * been shown to fail is not a guard. Uses a controlled fixture tree under
 * tests/Fixtures/ArchGuardFixtures/RetryOwnershipJobs/ (never the real app/
 * directory) so this proof is a permanent regression test, not a one-off
 * manual check.
 */
test('qwsDiscoverRetryOwnershipViolations recursively catches a ShouldQueue class in a nested subdirectory', function (): void {
    $fixtureRoot = base_path('tests/Fixtures/ArchGuardFixtures/RetryOwnershipJobs');

    $violations = qwsDiscoverRetryOwnershipViolations($fixtureRoot, 'Tests\\Fixtures\\ArchGuardFixtures\\RetryOwnershipJobs', []);

    // The nested, non-compliant fixture MUST be caught — this is exactly the
    // blind spot a non-recursive glob('*.php') would have silently missed.
    expect($violations)->toContain('Tests\\Fixtures\\ArchGuardFixtures\\RetryOwnershipJobs\\Nested\\NonCompliantNestedRetryJob');

    // The root-level compliant fixture must NOT be flagged (it declares both
    // $tries and $timeout).
    expect($violations)->not->toContain('Tests\\Fixtures\\ArchGuardFixtures\\RetryOwnershipJobs\\CompliantRetryJob');

    // A plain (non-ShouldQueue) class must never be flagged, regardless of depth.
    expect($violations)->not->toContain('Tests\\Fixtures\\ArchGuardFixtures\\RetryOwnershipJobs\\NotARetryJob');
})->group('arch');
