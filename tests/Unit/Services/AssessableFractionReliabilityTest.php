<?php

declare(strict_types=1);

/**
 * RED — Task 22.1: AssessableFractionReliability unit tests (C9 D5 R-A strategy).
 *
 * Verifies:
 * (a) SLF {5,3,-1} → reliability 0.6667 (2 assessed / 3 total); rendered 67% via ReliabilityRenderer.
 * (b) COL {5,3,3} → reliability 1.0 (3 assessed / 3 total); rendered 100%.
 * (c) All -1 → 0.0 (no throw, no NaN).
 *
 * Refs spec: D5 "Golden cassette — SLF reliability 67%", "COL reliability 100%",
 * "all indicators -1 → reliability 0.0".
 */

use App\Services\Scoring\AssessableFractionReliability;
use App\Services\Scoring\ReliabilityRenderer;

test('(a) SLF {5,3,-1} → reliability 2/3 ≈ 0.6667', function (): void {
    $strategy = new AssessableFractionReliability;
    $reliability = $strategy->compute([5, 3, -1]);

    // 2 assessed (5 and 3), 3 total → 2/3 ≈ 0.6667
    expect($reliability)->toBeFloat();
    expect(round($reliability, 4))->toBe(round(2 / 3, 4));
});

test('(a) SLF reliability rendered as 67% (round-before-cast)', function (): void {
    $strategy = new AssessableFractionReliability;
    $reliability = $strategy->compute([5, 3, -1]);

    $renderer = new ReliabilityRenderer;
    expect($renderer->render($reliability))->toBe(67);
});

test('(b) COL {5,3,3} → reliability 1.0 (all assessed)', function (): void {
    $strategy = new AssessableFractionReliability;
    $reliability = $strategy->compute([5, 3, 3]);

    expect($reliability)->toBe(1.0);
});

test('(b) COL reliability rendered as 100%', function (): void {
    $strategy = new AssessableFractionReliability;
    $reliability = $strategy->compute([5, 3, 3]);

    $renderer = new ReliabilityRenderer;
    expect($renderer->render($reliability))->toBe(100);
});

test('(c) all -1 → reliability 0.0 (no throw, no NaN)', function (): void {
    $strategy = new AssessableFractionReliability;
    $reliability = $strategy->compute([-1, -1, -1]);

    expect($reliability)->toBe(0.0);
});

test('empty array → reliability 0.0 (no division by zero)', function (): void {
    $strategy = new AssessableFractionReliability;
    $reliability = $strategy->compute([]);

    expect($reliability)->toBe(0.0);
});
