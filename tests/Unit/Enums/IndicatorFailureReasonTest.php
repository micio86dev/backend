<?php

declare(strict_types=1);

/**
 * RED — B2a.2: IndicatorFailureReason (C13, design.md D1/D7).
 *
 * Backs the INDICATOR-grain `indicator_scores.unassessable_reason` (product
 * owner override, 0.4 in tasks.md) — the sibling of `UnscorableReason`
 * (competency grain) and `AiRequestFailureReason` (ai_requests/call grain).
 * Exactly three values, per the scoring-engine spec's "Indicator
 * Validation-Failure Reason Vocabulary" requirement.
 */

use App\Enums\IndicatorFailureReason;

test('IndicatorFailureReason backs exactly the three vocabulary values', function (): void {
    expect(IndicatorFailureReason::ModelDeclared->value)->toBe('model_declared')
        ->and(IndicatorFailureReason::ExcerptUnverifiable->value)->toBe('excerpt_unverifiable')
        ->and(IndicatorFailureReason::ScoreIllegal->value)->toBe('score_illegal');
});

test('IndicatorFailureReason has exactly three cases — no silent widening', function (): void {
    expect(IndicatorFailureReason::cases())->toHaveCount(3);
});
