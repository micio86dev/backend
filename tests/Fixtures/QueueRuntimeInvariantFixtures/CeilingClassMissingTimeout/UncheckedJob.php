<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueueRuntimeInvariantFixtures\CeilingClassMissingTimeout;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueueRuntimeInvariant tests — the class configured as the
 * ceilingCheckClass constructor arg, which declares NO $timeout at all.
 * Drives the "{ceilingCheckClass} does not declare a $timeout" branch.
 */
final class UncheckedJob implements ShouldQueue
{
    public function handle(): void {}
}
