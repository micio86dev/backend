<?php

declare(strict_types=1);

/**
 * RED — A1.7: ScoringFailureClassifier (C13, design.md D3).
 *
 * `ScoringDisposition::RetryWithLargerBudget` is reachable from EXACTLY ONE
 * arm of one match: `ScoringFailure::ResponseTruncated`. Every other case —
 * present today or added in the future without updating this match — lands
 * in `default => Terminal`. This test asserts that property directly: a
 * data-provider loop over `ScoringFailure::cases()` (minus ResponseTruncated)
 * proves `classify()` returns Terminal at every attempt count, so a new case
 * added without thought fails this test rather than sliding through.
 */

use App\Enums\Scoring\ScoringDisposition;
use App\Enums\Scoring\ScoringFailure;
use App\Services\Scoring\ScoringFailureClassifier;

$nonTruncatedCases = array_values(array_filter(
    ScoringFailure::cases(),
    static fn (ScoringFailure $case): bool => $case !== ScoringFailure::ResponseTruncated,
));

test('every ScoringFailure case except ResponseTruncated is Terminal at every attempt count', function (ScoringFailure $failure): void {
    $classifier = new ScoringFailureClassifier;

    foreach ([0, 1, 2, 5] as $attemptsAlreadyMade) {
        expect($classifier->classify($failure, $attemptsAlreadyMade))
            ->toBe(ScoringDisposition::Terminal, "Expected Terminal for {$failure->name} at attempt {$attemptsAlreadyMade}.");
    }
})->with(array_map(static fn (ScoringFailure $case): array => [$case], $nonTruncatedCases));

test('ScoringFailure has at least the non-truncated cases plus ResponseTruncated', function (): void {
    expect(ScoringFailure::cases())->toContain(ScoringFailure::ResponseTruncated);
});

test('ResponseTruncated is Terminal when no retry attempts are configured (Increment A default)', function (): void {
    // Increment A ships no `scoring.truncation_retry` config block (that is B1's
    // scope) — the classifier MUST default to Terminal so a truncated response
    // is never silently left unfinalized while nothing wires the retry action.
    config(['scoring.truncation_retry' => null]);

    $classifier = new ScoringFailureClassifier;

    expect($classifier->classify(ScoringFailure::ResponseTruncated, 0))
        ->toBe(ScoringDisposition::Terminal);
});

// ─── B1: the retry action, once config.truncation_retry ships (D8) ──────────

test('ResponseTruncated at attempt 0 retries when max_attempts allows it', function (): void {
    config([
        'scoring.truncation_retry.enabled' => true,
        'scoring.truncation_retry.max_attempts' => 1,
    ]);

    $classifier = new ScoringFailureClassifier;

    expect($classifier->classify(ScoringFailure::ResponseTruncated, 0))
        ->toBe(ScoringDisposition::RetryWithLargerBudget);
});

test('ResponseTruncated at attempt 1 is Terminal once max_attempts (1) is exhausted — no third call, ever', function (): void {
    config([
        'scoring.truncation_retry.enabled' => true,
        'scoring.truncation_retry.max_attempts' => 1,
    ]);

    $classifier = new ScoringFailureClassifier;

    expect($classifier->classify(ScoringFailure::ResponseTruncated, 1))
        ->toBe(ScoringDisposition::Terminal);
});

test('enabled=false is a kill-switch: Terminal at every attempt count regardless of max_attempts', function (): void {
    // The 'enabled' flag would be dead config if it did not gate the retry
    // action — an operator disabling the feature must get IDENTICAL behavior
    // to Increment A's no-config-block default, not a max_attempts that is
    // still silently honored underneath.
    config([
        'scoring.truncation_retry.enabled' => false,
        'scoring.truncation_retry.max_attempts' => 1,
    ]);

    $classifier = new ScoringFailureClassifier;

    expect($classifier->classify(ScoringFailure::ResponseTruncated, 0))
        ->toBe(ScoringDisposition::Terminal)
        ->and($classifier->classify(ScoringFailure::ResponseTruncated, 1))
        ->toBe(ScoringDisposition::Terminal);
});
