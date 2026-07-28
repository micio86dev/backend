<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueueRuntimeInvariantFixtures\Empty;

/**
 * Fixture for QueueRuntimeInvariant tests — a directory with NO ShouldQueue
 * implementors at all, driving the "No ShouldQueue job declares a $timeout"
 * early-return branch. A plain class sits here so the directory is not
 * literally empty (a genuinely empty directory would exercise the same
 * branch trivially).
 */
final class NotAJob
{
    public function handle(): void {}
}
