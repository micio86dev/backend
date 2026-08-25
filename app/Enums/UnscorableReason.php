<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a competency was NOT scored (C9/C13, scoring-failure-containment D1/D2).
 *
 * Backs `competency_results.unscorable_reason` — one competency, after ALL
 * attempts (which may span two `ai_requests` rows under the truncation
 * retry, D8). Audience is BOTH the operator (admin-backoffice) and the
 * integrator (webhooks-integration): this is the value that already ships
 * additively in the `evaluation` webhook payload (C-B).
 *
 * Deliberately an enum in PHP and a PLAIN STRING in Postgres (D2): used at
 * every write site (`ScoreEvaluationJob::persistUnscorable()`, factories,
 * `DemoWriter`) as the single source of the value set, but with NO Eloquent
 * cast and NO Postgres CHECK constraint. A value-enumerating CHECK would buy
 * DB-level enforcement at the cost of a rollback data precondition — the
 * exact hazard `ai_requests.failure_reason` does NOT have (C-A) — and would
 * turn a read of an out-of-domain legacy value into a thrown ValueError
 * instead of a loud, renderable fallback (the backoffice's
 * `unscorableReasonKey()`, D12, absorbs anything unrecognised).
 *
 * Deliberately similarly named to, but a DIFFERENT column/grain from,
 * `AiRequestFailureReason::Truncated` (`ai_requests.failure_reason`, one
 * provider call). See that enum's docblock for the distinction.
 */
enum UnscorableReason: string
{
    /** No BARS indicators are defined for this competency in the pinned framework version. */
    case RoleNoBars = 'role_no_bars';

    /** The BARS anchors are not available in this project's language. */
    case AnchorTranslationMissing = 'anchor_translation_missing';

    /** The evaluator's response could not be read after fence/prose tolerance. */
    case LlmParseError = 'llm_parse_error';

    /**
     * The evaluator's response was cut off before it was complete, even after
     * the truncation-only retry at an enlarged budget (D3/D4/D8).
     */
    case LlmTruncated = 'llm_truncated';
}
