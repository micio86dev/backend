<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueueRuntimeInvariantFixtures\BelowFloor;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueueRuntimeInvariant tests — a job whose timeout (500s) is
 * BELOW the 600s config-independent floor. Also used to reproduce the exact
 * degenerate case the floor exists to catch: with
 * scoring.anthropic.timeout_seconds shrunk low enough, the DERIVED ceiling
 * collapses below 500s too, so Assertion B alone would pass — only
 * Assertion C (the floor) still fails.
 */
final class ScoreLikeJob implements ShouldQueue
{
    public int $timeout = 500;

    public function handle(): void {}
}
