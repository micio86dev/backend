<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueueRuntimeInvariantFixtures\CeilingViolation;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueueRuntimeInvariant tests — a job whose timeout (700s)
 * clears the 600s config-independent floor but NOT the derived ceiling
 * under the default test config (18 x 60 x 1.1 = 1188s). Isolates the
 * ceiling violation from the floor violation.
 */
final class ScoreLikeJob implements ShouldQueue
{
    public int $timeout = 700;

    public function handle(): void {}
}
