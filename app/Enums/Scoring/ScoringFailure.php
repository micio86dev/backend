<?php

declare(strict_types=1);

namespace App\Enums\Scoring;

/**
 * What went wrong while scoring one competency's LLM call — a
 * PROVIDER-NEUTRAL vocabulary, deliberately separate from
 * `App\Enums\AiRequestFailureReason` (the persisted, provider-agnostic-but-
 * DB-facing value set). This enum exists purely to be classified by
 * `ScoringFailureClassifier` (C13, design.md D3): "what went wrong" is a
 * different question from "what is allowed to happen next" — that second
 * question is `ScoringDisposition`.
 */
enum ScoringFailure
{
    /** The response body was not valid JSON, or not the expected shape. */
    case ParseError;

    /** The model returned a different number of indicators than the framework defines. */
    case IndicatorCountMismatch;

    /** A score outside the discrete set {1,2,3,4,5} ∪ {-1}. */
    case InvalidIndicatorScore;

    /** An excerpt that is not verbatim from the transcript. */
    case ExcerptNotVerbatim;

    /** The provider itself errored: non-2xx, transport failure, malformed envelope. */
    case ProviderError;

    /** The call exceeded its time budget. */
    case Timeout;

    /**
     * The provider response was cut off at the configured max-output-tokens
     * budget. THE ONLY case reachable from `ScoringDisposition::RetryWithLargerBudget`
     * — deterministic at budget N, not deterministic at 2N, so the retry is a
     * remedy rather than a gamble (D3/D4).
     */
    case ResponseTruncated;
}
