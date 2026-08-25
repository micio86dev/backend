<?php

declare(strict_types=1);

/**
 * RED — A1.5: UnscorableReason enum (C13, D1/D2/D4).
 *
 * Backs `competency_results.unscorable_reason` — a plain string column with
 * NO Postgres CHECK and NO Eloquent cast (D2): the enum is a write-side and
 * vocabulary device only. Holds the three values already in production plus
 * the new truncation case.
 */

use App\Enums\UnscorableReason;

test('the three pre-existing values are backed', function (): void {
    expect(UnscorableReason::RoleNoBars->value)->toBe('role_no_bars')
        ->and(UnscorableReason::AnchorTranslationMissing->value)->toBe('anchor_translation_missing')
        ->and(UnscorableReason::LlmParseError->value)->toBe('llm_parse_error');
});

test('UnscorableReason::LlmTruncated exists with value llm_truncated', function (): void {
    expect(UnscorableReason::LlmTruncated->value)->toBe('llm_truncated');
});

test('UnscorableReason has exactly four cases', function (): void {
    expect(UnscorableReason::cases())->toHaveCount(4);
});
