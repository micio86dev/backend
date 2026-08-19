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
 * PR3 additive widening (design D9): one further trailing nullable field for the
 * composed opening greeting. Null ⇒ the provider OMITS its greeting field entirely
 * (`opening_text` on HeyGen `/contexts`, `custom_greeting` on Tavus `/conversations`)
 * — same "unset means absent" convention TemplatePayload already uses.
 *
 * REQ: QuestionContext DTO (C7a)
 * REQ: QuestionContext Carries Composed Prompt (C8 — task 4.2)
 * REQ: QuestionContext Carries a Composed Opening Greeting (PR3 — delta spec, interview-conversation)
 */
readonly class QuestionContext
{
    public function __construct(
        public string $competencyCode,
        public int $questionIndex,
        // C8: nullable trailing params — null preserves exact C7a provider body (backward-compatible).
        public ?string $systemPrompt = null,
        public ?string $promptVersion = null,
        // PR3: nullable trailing param — null preserves exact pre-PR3 provider body (backward-compatible).
        public ?string $openingText = null,
    ) {}
}
