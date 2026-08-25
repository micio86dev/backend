<?php

declare(strict_types=1);

namespace App\Enums\Scoring;

/**
 * What we are allowed to do about a `ScoringFailure` (C13, design.md D3).
 *
 * Exactly two cases, deliberately. `RetryWithLargerBudget` is reachable from
 * exactly one arm of `ScoringFailureClassifier::classify()`'s match. Every
 * other failure — including any failure class added in the future without
 * updating that match — lands in `default => Terminal`. Adding a retryable
 * class is therefore not an omission but an EDIT TO THE ONLY LINE that grants
 * retry, which a reviewer sees in the diff.
 */
enum ScoringDisposition
{
    /** No further attempt is made; the competency is finalized as unscorable. */
    case Terminal;

    /** Retry the SAME call once more, at an enlarged max_tokens budget (D8). */
    case RetryWithLargerBudget;
}
