<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an LLM call's RESULT was unusable (C13, observability delta).
 *
 * A closed set of machine keys, and closed on purpose. The obvious alternative
 * — storing the provider's error string — is a confidentiality leak with a
 * dashboard in front of it: a provider error can echo prompt content, and
 * prompts contain candidate answers. This table is read by an org-scoped cost
 * view.
 *
 * A failure class is also the only granularity the dashboard needs. "23% of
 * spend this month went on calls whose JSON would not parse" is actionable;
 * the raw text of each one is not.
 *
 * NULL on rows where `success` is true — enforced by a CHECK constraint, in
 * both directions.
 *
 * Machine values; never localized.
 */
enum AiRequestFailureReason: string
{
    /** The response body was not valid JSON, or not the expected shape. */
    case ParseError = 'llm_parse_error';

    /** The model returned a different number of indicators than the framework defines. */
    case IndicatorCountMismatch = 'indicator_count_mismatch';

    /** A score outside the discrete set {1,2,3,4,5} ∪ {-1}. */
    case InvalidIndicatorScore = 'invalid_indicator_score';

    /** An excerpt that is not verbatim from the transcript — the model invented evidence. */
    case ExcerptNotVerbatim = 'excerpt_not_verbatim';

    /** The provider itself errored: non-2xx, transport failure, malformed envelope. */
    case ProviderError = 'provider_error';

    /** The call exceeded its time budget. Billed or not, it consumed a worker slot. */
    case Timeout = 'timeout';
}
