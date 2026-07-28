<?php

declare(strict_types=1);

namespace Tests\Fixtures\ArchGuardFixtures\Jobs;

/**
 * Fixture for QueuedJobTenantContextArchTest.php's recursive-discovery proof test.
 *
 * A plain class (NOT ShouldQueue) sitting alongside real jobs — must be ignored by
 * the discovery scan regardless of recursion depth.
 */
final class NotAJob
{
    public function handle(): void {}
}
