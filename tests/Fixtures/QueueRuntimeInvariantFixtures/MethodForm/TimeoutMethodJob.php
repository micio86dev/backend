<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueueRuntimeInvariantFixtures\MethodForm;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueueRuntimeInvariant tests — declares its timeout via the
 * timeout() METHOD form rather than the $timeout property. No production
 * job in this codebase uses this form today, but the discovery walker
 * supports it and must be proven to actually work, not just assumed.
 */
final class TimeoutMethodJob implements ShouldQueue
{
    public function handle(): void {}

    public function timeout(): int
    {
        return 1200;
    }
}
