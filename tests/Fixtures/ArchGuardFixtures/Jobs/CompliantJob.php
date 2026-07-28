<?php

declare(strict_types=1);

namespace Tests\Fixtures\ArchGuardFixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueuedJobTenantContextArchTest.php's recursive-discovery proof test.
 *
 * A ShouldQueue class at the ROOT of the scanned tree that references
 * TenantContextScope:: — must NOT be flagged as a violation.
 */
final class CompliantJob implements ShouldQueue
{
    public function handle(): void
    {
        // App\Support\Tenancy\TenantContextScope::runFor(1, fn () => null);
    }
}
