<?php

declare(strict_types=1);

namespace Tests\Fixtures\ArchGuardFixtures\RetryOwnershipJobs;

/**
 * Fixture for QueuedJobRetryOwnershipArchTest.php's recursive-discovery proof
 * test.
 *
 * A plain class (NOT ShouldQueue) sitting alongside real jobs — must be
 * ignored by the discovery scan regardless of recursion depth.
 */
final class NotARetryJob
{
    public function handle(): void {}
}
