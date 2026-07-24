<?php

declare(strict_types=1);

namespace App\Services\Provider;

/**
 * Context passed to ProviderSessionService::issue() for provider session initialization.
 *
 * Carries the competency and question index so the provider can contextualize the session.
 * The full question text and greeting are C7b's contract; C7a passes the minimal identifiers.
 *
 * C8 additive widening: two trailing nullable fields for the composed system prompt
 * and its version. Both default to null — the C7a null path keeps the exact pre-C8
 * provider create-body behavior (backward-compatible single production call site).
 *
 * REQ: QuestionContext DTO (C7a)
 * REQ: QuestionContext Carries Composed Prompt (C8 — task 4.2)
 */
readonly class QuestionContext
{
    public function __construct(
        public string  $competencyCode,
        public int     $questionIndex,
        // C8: nullable trailing params — null preserves exact C7a provider body (backward-compatible).
        public ?string $systemPrompt  = null,
        public ?string $promptVersion = null,
    ) {}
}
