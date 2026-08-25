<?php

declare(strict_types=1);

/**
 * RED — A1.3: AiRequestFailureReason gains a Truncated case (C13, observability
 * delta, D3/C-A).
 *
 * Per C-A/D2, `ai_requests_failure_reason_check` is presence-based, not a
 * value-enumerating CHECK — adding a case is a one-case enum edit with no
 * migration and no deploy-order gate.
 */

use App\Enums\AiRequestFailureReason;

test('AiRequestFailureReason::Truncated exists with value truncated', function (): void {
    expect(AiRequestFailureReason::Truncated->value)->toBe('truncated');
});

test('the six pre-existing cases are unchanged', function (): void {
    expect(AiRequestFailureReason::ParseError->value)->toBe('llm_parse_error')
        ->and(AiRequestFailureReason::IndicatorCountMismatch->value)->toBe('indicator_count_mismatch')
        ->and(AiRequestFailureReason::InvalidIndicatorScore->value)->toBe('invalid_indicator_score')
        ->and(AiRequestFailureReason::ExcerptNotVerbatim->value)->toBe('excerpt_not_verbatim')
        ->and(AiRequestFailureReason::ProviderError->value)->toBe('provider_error')
        ->and(AiRequestFailureReason::Timeout->value)->toBe('timeout');
});

test('AiRequestFailureReason now has exactly seven cases', function (): void {
    expect(AiRequestFailureReason::cases())->toHaveCount(7);
});
