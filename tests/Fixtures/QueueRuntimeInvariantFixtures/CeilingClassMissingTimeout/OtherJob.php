<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueueRuntimeInvariantFixtures\CeilingClassMissingTimeout;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueueRuntimeInvariant tests — an ordinary compliant job. Its
 * presence keeps $declared non-empty so violations() proceeds past the
 * early-return branch and reaches the ceiling-check-class lookup, where
 * UncheckedJob (same directory) is the class under test.
 */
final class OtherJob implements ShouldQueue
{
    public int $timeout = 60;

    public function handle(): void {}
}
