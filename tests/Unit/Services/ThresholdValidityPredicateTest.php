<?php

declare(strict_types=1);

/**
 * RED — Task 22.3: ThresholdValidityPredicate unit tests (C9 D5 V-A strategy).
 *
 * Verifies:
 * (a) reliability 0.5, T=0.50 → VALID (boundary: >=).
 * (b) reliability 0.33, T=0.50 → INVALID.
 * (c) SCORING_VALIDITY_THRESHOLD=0.75, reliability 0.67 → INVALID (injected threshold).
 *
 * Refs spec: D5 "Valid competency at default threshold",
 * "Invalid competency below threshold", "T is injectable and configurable".
 */

use App\Services\Scoring\ThresholdValidityPredicate;

test('(a) reliability 0.5 at default threshold 0.5 → VALID (boundary >= is inclusive)', function (): void {
    config(['scoring.validity_threshold' => 0.5]);

    $predicate = new ThresholdValidityPredicate;
    expect($predicate->isValid(0.5))->toBeTrue();
});

test('(b) reliability 0.33 at default threshold 0.5 → INVALID', function (): void {
    config(['scoring.validity_threshold' => 0.5]);

    $predicate = new ThresholdValidityPredicate;
    expect($predicate->isValid(0.33))->toBeFalse();
});

test('(c) threshold 0.75: reliability 0.67 → INVALID', function (): void {
    config(['scoring.validity_threshold' => 0.75]);

    $predicate = new ThresholdValidityPredicate;
    expect($predicate->isValid(0.67))->toBeFalse();
});

test('(c) threshold 0.75: reliability 0.75 → VALID (boundary)', function (): void {
    config(['scoring.validity_threshold' => 0.75]);

    $predicate = new ThresholdValidityPredicate;
    expect($predicate->isValid(0.75))->toBeTrue();
});

test('reliability 1.0 always valid', function (): void {
    config(['scoring.validity_threshold' => 0.5]);

    $predicate = new ThresholdValidityPredicate;
    expect($predicate->isValid(1.0))->toBeTrue();
});

test('reliability 0.0 always invalid at default threshold', function (): void {
    config(['scoring.validity_threshold' => 0.5]);

    $predicate = new ThresholdValidityPredicate;
    expect($predicate->isValid(0.0))->toBeFalse();
});
