<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

use App\Enums\Audit\AuditOutcomeReason;

/**
 * The result of one `AuditJudge::judge()` call (design D2). `$verdicts` is
 * keyed by `indicatorScoreId`, never positional — the exact fragility
 * `EvaluationParser` already documents and pays for with
 * `IndicatorCountMismatchException` (C9 D4 FIX-8) is deliberately not
 * reintroduced here: a subject absent from `$verdicts` is detected
 * structurally (its ordinal key was never answered), never by counting.
 *
 * `$omissions` resolves the gap P1 flagged forward to P3b: `$verdicts`
 * alone tells the caller a subject was omitted, but not WHY. Every subject
 * sent that does not land in `$verdicts` MUST have a corresponding entry
 * here (`JevResponseMapper`'s own invariant) — that reason is what
 * `AuditEvaluationJob` persists as the `malformed` row's `outcome_reason`
 * (design C-E).
 */
final readonly class AuditBatchResult
{
    /**
     * @param  array<int, AuditVerdict>  $verdicts  keyed by indicatorScoreId — surviving verdicts only
     * @param  array<int, AuditOutcomeReason>  $omissions  keyed by indicatorScoreId — WHY a sent subject has no verdict
     */
    public function __construct(
        public array $verdicts,
        public array $omissions,
        public int $inputTokens,
        public int $outputTokens,
        public string $judgeModel,
        public int $latencyMs,
    ) {}
}
