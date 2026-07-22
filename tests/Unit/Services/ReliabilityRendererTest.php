<?php

declare(strict_types=1);

/**
 * RED — Task 22.2: ReliabilityRenderer unit tests (C9 D4 FIX-1 round-before-cast).
 *
 * Verifies:
 * (a) 0.6667 → 67 (round-before-cast, NOT (int)(0.6667*100) = 66).
 * (b) 1.0 → 100.
 *
 * Confirms the round-before-cast invariant explicitly:
 *   (int) round($reliabilityDbValue * 100, 0, PHP_ROUND_HALF_UP)
 *   NOT: (int)($reliabilityDbValue * 100) which truncates toward zero.
 *
 * Refs spec: D4 FIX-1 "Reliability rendering trap — round-before-cast, always".
 */

use App\Services\Scoring\ReliabilityRenderer;

test('(a) 0.6667 → 67 (round-before-cast, prevents truncation to 66)', function (): void {
    $renderer = new ReliabilityRenderer;

    // 0.6667 * 100 = 66.67 → round → 67
    // (int)(0.6667 * 100) = (int)(66.67) = 66 ← WRONG (truncation)
    expect($renderer->render(0.6667))->toBe(67);
});

test('(a) verify: naive (int)($value * 100) would give 66 for 0.6667', function (): void {
    // This test documents the trap: (int)(0.6667 * 100) === 66, not 67.
    // The renderer MUST use round() before cast.
    $naiveTruncation = (int) (0.6667 * 100);
    expect($naiveTruncation)->toBe(66); // the WRONG result our renderer avoids
});

test('(b) 1.0 → 100', function (): void {
    $renderer = new ReliabilityRenderer;
    expect($renderer->render(1.0))->toBe(100);
});

test('0.0 → 0', function (): void {
    $renderer = new ReliabilityRenderer;
    expect($renderer->render(0.0))->toBe(0);
});

test('0.5 → 50', function (): void {
    $renderer = new ReliabilityRenderer;
    expect($renderer->render(0.5))->toBe(50);
});

test('0.6667 stored value (2/3 rounded) → 67', function (): void {
    // Simulate what would be stored in numeric(5,4) after compute([5,3,-1]):
    // 2/3 ≈ 0.66666666..., stored as 0.6667
    $renderer = new ReliabilityRenderer;
    $stored = round(2 / 3, 4); // 0.6667

    expect($renderer->render($stored))->toBe(67);
});
