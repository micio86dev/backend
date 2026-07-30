<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueueRuntimeInvariantFixtures\UninitializedPropertyGuard;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueueRuntimeInvariant tests — a job whose timeout() method
 * form reads a typed property that is only initialized in the constructor.
 * Discovery uses ReflectionClass::newInstanceWithoutConstructor(), so
 * invoking timeout() here throws \Error: "must not be accessed before
 * initialization" — proving the discovery walker's try/catch guard treats
 * this as "undeclared" rather than crashing --validate-only or the health
 * probe over a job that simply cannot be evaluated outside a real dispatch.
 */
final class UninitializedPropertyTimeoutJob implements ShouldQueue
{
    private readonly int $multiplier;

    public function __construct(int $multiplier = 10)
    {
        $this->multiplier = $multiplier;
    }

    public function handle(): void {}

    public function timeout(): int
    {
        return $this->multiplier * 100;
    }
}
