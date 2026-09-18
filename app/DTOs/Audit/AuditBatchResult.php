<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * The result of one `AuditJudge::judge()` call (design D2). `$verdicts` is
 * keyed by `indicatorScoreId`, never positional — the exact fragility
 * `EvaluationParser` already documents and pays for with
 * `IndicatorCountMismatchException` (C9 D4 FIX-8) is deliberately not
 * reintroduced here: a subject absent from `$verdicts` is detected
 * structurally (its ordinal key was never answered), never by counting.
 */
final readonly class AuditBatchResult
{
    /** @param  array<int, AuditVerdict>  $verdicts  keyed by indicatorScoreId */
    public function __construct(
        public array $verdicts,
        public int $inputTokens,
        public int $outputTokens,
        public string $judgeModel,
        public int $latencyMs,
    ) {}
}
