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

// ─── PR3 widening: openingText (design D9) ───────────────────────────────────

test('(d) QuestionContext with five args: openingText is accessible', function (): void {
    $ctx = new QuestionContext(
        competencyCode: 'PRS',
        questionIndex: 0,
        systemPrompt: 'You are an adaptive interviewer.',
        promptVersion: 'conv-2026-07-23',
        openingText: "Let's talk about Problem Solving.",
    );

    expect($ctx->openingText)->toBe("Let's talk about Problem Solving.");
});

test('(e) QuestionContext without openingText: defaults to null (backward-compatible)', function (): void {
    $ctx = new QuestionContext(
        competencyCode: 'COL',
        questionIndex: 2,
        systemPrompt: 'prompt',
        promptVersion: 'v1',
    );

    expect($ctx->openingText)->toBeNull();
});

test('(f) QuestionContext four-arg C8 construction remains backward-compatible after the PR3 widening', function (): void {
    // Mirrors the C8-era four-arg call sites — must still compile and default openingText to null.
    $ctx = new QuestionContext(
        competencyCode: 'STG',
        questionIndex: 1,
        systemPrompt: null,
        promptVersion: null,
    );

    expect($ctx->openingText)->toBeNull();
});
