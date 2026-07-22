<?php

declare(strict_types=1);

/**
 * RED — Task 14.6: MeanCalculator (C9 D4 CC2/CC3 — correctness-critical zone ~95%).
 *
 * Verifies:
 * (a) COL {5,3,3} → 3.67 (standard half-up, PHP_ROUND_HALF_UP).
 * (b) SLF {5,3,-1} → 4.0 (denominator = 2, not 3).
 * (c) All -1 → null (no throw, no NaN, no divide-by-zero).
 *
 * Refs spec: D4 CC2/CC3, "Golden cassette" scenarios.
 */

use App\Services\Scoring\MeanCalculator;

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) COL {5,3,3} → 3.67 (PHP_ROUND_HALF_UP, 2 decimal places)', function (): void {
    $calc = new MeanCalculator;

    $result = $calc->compute([5, 3, 3]);

    // (5+3+3)/3 = 3.666... → rounded to 2dp half-up → 3.67
    expect($result)->toBe(3.67);
});

test('(b) SLF {5,3,-1} → 4.0 (denominator=2, -1 excluded)', function (): void {
    $calc = new MeanCalculator;

    $result = $calc->compute([5, 3, -1]);

    // Assessed set: {5,3} → (5+3)/2 = 4.0
    expect($result)->toBe(4.0);
});

test('(c) all -1 → null (no throw, no NaN, no divide-by-zero)', function (): void {
    $calc = new MeanCalculator;

    $result = $calc->compute([-1, -1, -1]);

    expect($result)->toBeNull();
});

test('all 1s → 1.0', function (): void {
    $calc = new MeanCalculator;

    $result = $calc->compute([1, 1, 1]);

    expect($result)->toBe(1.0);
});

test('{5,3,5} → 4.33', function (): void {
    $calc = new MeanCalculator;

    $result = $calc->compute([5, 3, 5]);

    // (5+3+5)/3 = 4.333... → 4.33
    expect($result)->toBe(4.33);
});

test('{1,1,3} → 1.67', function (): void {
    $calc = new MeanCalculator;

    $result = $calc->compute([1, 1, 3]);

    // (1+1+3)/3 = 1.666... → 1.67
    expect($result)->toBe(1.67);
});

test('single assessed score → returned as-is (2dp)', function (): void {
    $calc = new MeanCalculator;

    $result = $calc->compute([5]);

    expect($result)->toBe(5.0);
});

test('single assessed with unassessable → mean of single', function (): void {
    $calc = new MeanCalculator;

    // Only 5 is assessed, -1 excluded
    $result = $calc->compute([-1, 5]);

    expect($result)->toBe(5.0);
});
