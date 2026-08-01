<?php

declare(strict_types=1);

namespace Tests\Fixtures\ArchGuardFixtures\RetryOwnershipJobs\Nested;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueuedJobRetryOwnershipArchTest.php's recursive-discovery proof
 * test.
 *
 * A ShouldQueue class living in a NESTED subdirectory that declares NEITHER
 * $tries nor $timeout — this is exactly the blind spot a non-recursive
 * `glob('app/Jobs/*.php')` scan would silently miss. MUST be flagged as a
 * violation once discovery is recursive.
 */
final class NonCompliantNestedRetryJob implements ShouldQueue
{
    public function handle(): void
    {
        // Intentionally empty — no $tries, no $timeout, no tries()/timeout() methods.
    }
}
