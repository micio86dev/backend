<?php

declare(strict_types=1);

namespace App\Enums\Audit;

/**
 * Why an `indicator_score_audits` row is NOT `judged` (proposal AD-4,
 * design C-E). Named `outcome_reason`, not `skip_reason` — the column also
 * carries `unavailable` and `malformed` reasons, and a column literally
 * named `skip_reason` on an `unavailable` row would say something false
 * about the row it is on. This is `IndicatorFailureReason`'s own
 * "deliberately named, NOT a misnomer" doctrine applied one grain down.
 *
 * Deliberately UNCONSTRAINED at the database (design D4): only the status
 * column is CHECK-enumerated, so this vocabulary MAY extend later without a
 * migration — the same asymmetry `unassessable_reason`'s migration states
 * for itself.
 */
enum AuditOutcomeReason: string
{
    // status = skipped (AD-4)
    /** score = -1 — the scorer asserted no assessable evidence; nothing to check. */
    case UnassessableByConstruction = 'unassessable_by_construction';

    /** score ∈ {1..5} with excerpts = [] — a scored indicator citing no evidence. */
    case AssessedWithoutExcerpts = 'assessed_without_excerpts';

    // status = unavailable — the competency-level judge call itself threw
    case JudgeUnreachable = 'judge_unreachable';

    case JudgeHttpError = 'judge_http_error';

    case JudgeTimeout = 'judge_timeout';

    // status = malformed — the vendor answered, but this subject's verdict was unusable
    /** This subject's ordinal key is absent from the response. */
    case VerdictMissing = 'verdict_missing';

    /** One of this subject's three probabilities is absent or non-numeric. */
    case VerdictUnparseable = 'verdict_unparseable';

    /** One of this subject's three probabilities falls outside [0, 1]. */
    case ProbabilityOutOfDomain = 'probability_out_of_domain';
}
