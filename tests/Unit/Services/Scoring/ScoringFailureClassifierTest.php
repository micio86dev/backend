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
