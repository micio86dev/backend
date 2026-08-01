<?php

declare(strict_types=1);

/**
 * RED — Task 4.1: QuestionContext widening (C8 Phase 4).
 *
 * Verifies:
 * (a) Four-arg construction: systemPrompt and promptVersion accessible.
 * (b) Two-arg construction: systemPrompt and promptVersion default to null.
 * (c) Existing two-arg sites are backward-compatible (no third-arg required).
 *
 * Spec: REQ QuestionContext Carries Composed Prompt · delta spec C7a addendum.
 * REQ: QuestionContext widening (C8 Phase 4 — task 4.2)
 */

use App\Services\Provider\QuestionContext;

test('(a) QuestionContext with four args: systemPrompt and promptVersion are accessible', function (): void {
    $ctx = new QuestionContext(
        competencyCode: 'PRS',
        questionIndex: 0,
        systemPrompt: 'You are an adaptive interviewer.',
        promptVersion: 'conv-2026-07-23',
    );

    expect($ctx->competencyCode)->toBe('PRS');
    expect($ctx->questionIndex)->toBe(0);
    expect($ctx->systemPrompt)->toBe('You are an adaptive interviewer.');
    expect($ctx->promptVersion)->toBe('conv-2026-07-23');
});

test('(b) QuestionContext with two args: systemPrompt and promptVersion default to null', function (): void {
    $ctx = new QuestionContext(
        competencyCode: 'COL',
        questionIndex: 2,
    );

    expect($ctx->competencyCode)->toBe('COL');
    expect($ctx->questionIndex)->toBe(2);
    expect($ctx->systemPrompt)->toBeNull();
    expect($ctx->promptVersion)->toBeNull();
});

test('(c) QuestionContext is backward-compatible: positional two-arg call still works', function (): void {
    // This mirrors the existing production call at InterviewController.php:98
    $ctx = new QuestionContext('STG', 1);

    expect($ctx->competencyCode)->toBe('STG');
    expect($ctx->questionIndex)->toBe(1);
    expect($ctx->systemPrompt)->toBeNull();
    expect($ctx->promptVersion)->toBeNull();
});
