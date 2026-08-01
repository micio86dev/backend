<?php

declare(strict_types=1);

namespace Tests\Fixtures\ArchGuardFixtures\RetryOwnershipJobs;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueuedJobRetryOwnershipArchTest.php's recursive-discovery proof
 * test.
 *
 * A ShouldQueue class at the ROOT of the scanned tree that declares BOTH its
 * own $tries and $timeout — must NOT be flagged as a violation.
 */
final class CompliantRetryJob implements ShouldQueue
{
    public int $tries = 3;

    public int $timeout = 60;

    public function handle(): void {}
}
