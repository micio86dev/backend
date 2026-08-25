<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\Enums\Scoring\ScoringDisposition;
use App\Enums\Scoring\ScoringFailure;

/**
 * Classifies a `ScoringFailure` into a `ScoringDisposition` (C13, design.md D3).
 *
 * The default arm is `Terminal` BY THE SHAPE OF THE MATCH, not by a comment —
 * D4 FIX-9 ("no queue retry for deterministic parse/validation failures") is
 * enforced structurally: `RetryWithLargerBudget` is reachable from exactly
 * one arm, `ScoringFailure::ResponseTruncated`, and only while attempts
 * remain under the configured cap.
 *
 * Increment A ships no `scoring.truncation_retry` config block (B1's scope)
 * — `maxAttempts()` therefore defaults to 0, so `classify()` always returns
 * `Terminal` for `ResponseTruncated` until B1's config ships a positive
 * `max_attempts`. This lets Increment A call this same classifier from the
 * job's truncation short-circuit WITHOUT any behavior change once B1 lands:
 * only the config default moves, never this class.
 */
final class ScoringFailureClassifier
{
    public function classify(ScoringFailure $failure, int $attemptsAlreadyMade): ScoringDisposition
    {
        return match ($failure) {
            ScoringFailure::ResponseTruncated => $attemptsAlreadyMade < $this->maxAttempts()
                ? ScoringDisposition::RetryWithLargerBudget
                : ScoringDisposition::Terminal,
            default => ScoringDisposition::Terminal,   // D4 FIX-9 — fence, prose,
        };                                              // count mismatch, illegal score,
    }                                                   // non-verbatim excerpt, provider error

    private function maxAttempts(): int
    {
        return (int) config('scoring.truncation_retry.max_attempts', 0);
    }
}
