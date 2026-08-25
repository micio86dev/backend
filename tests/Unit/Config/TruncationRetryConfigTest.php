<?php

declare(strict_types=1);

/**
 * RED — B1.1: shipped truncation-retry config defaults (C13, design.md D8).
 *
 * Config-driven per the ratified product answer, with a config-invariant test
 * instead of a hardcoded cap: the SHIPPED defaults are pinned here so
 * changing the cap requires editing this test — deliberate and visible —
 * while an operator retains the env override the product asked for. Same
 * idiom as tests/Unit/QueueRuntimeConfigTest.php and PromptBuilderTest's
 * prompt_version parity guard.
 */

test('shipped truncation_retry defaults: enabled=true, max_attempts=1, budget_multiplier=2.0, budget_ceiling=8192', function (): void {
    expect(config('scoring.truncation_retry.enabled'))->toBeTrue()
        ->and(config('scoring.truncation_retry.max_attempts'))->toBe(1)
        ->and(config('scoring.truncation_retry.budget_multiplier'))->toBe(2.0)
        ->and(config('scoring.truncation_retry.budget_ceiling'))->toBe(8192);
});
