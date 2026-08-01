<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueueRuntimeInvariantFixtures\AllCompliant;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueueRuntimeInvariant tests — a job that clears the derived
 * ceiling and the config-independent floor under the default test config
 * (scoring.anthropic.timeout_seconds=60 -> derived ceiling 1188s).
 */
final class ScoreLikeJob implements ShouldQueue
{
    public int $timeout = 1200;

    public function handle(): void {}
}
