<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a single indicator's `-1` score exists (C13, scoring-failure-containment
 * D1/D7). Backs `indicator_scores.unassessable_reason` — one INDICATOR, the
 * finest of the three failure-vocabulary grains this change introduces:
 * `AiRequestFailureReason` (`ai_requests.failure_reason`, one provider call),
 * `UnscorableReason` (`competency_results.unscorable_reason`, one competency
 * after all attempts), `IndicatorFailureReason` (`indicator_scores.
 * unassessable_reason`, one indicator). Three different names for three
 * different grains — never reused, never conflated.
 *
 * Deliberately named `unassessable_reason` at every layer it touches (DB
 * column, `IndicatorScoreDTO` property, API field, i18n key base) — NOT
 * `reason` (too vague to read at a call site) and NOT `failure_reason`
 * (a misnomer: `ModelDeclared` is the model answering HONESTLY that it found
 * no evidence, not a failure of anything).
 *
 * `unassessable_reason` is METADATA ONLY: no scoring formula (`MeanCalculator`,
 * `AssessableFractionReliability`, `CompletionGate`) may read it — enforced by
 * tests/Arch/ScoringFormulaIsolationTest.php (D9).
 */
enum IndicatorFailureReason: string
{
    /** The LLM itself returned -1 — no assessable evidence in the transcript. */
    case ModelDeclared = 'model_declared';

    /** The LLM claimed evidence that failed the verbatim-substring check. */
    case ExcerptUnverifiable = 'excerpt_unverifiable';

    /** The LLM returned a value outside {1,2,3,4,5,-1}. */
    case ScoreIllegal = 'score_illegal';
}
