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
 * Hotfix 0.22.1 additive widening: one further trailing nullable field for the
 * session's spoken language. BEAI is multi-tenant and multilingual (CLAUDE.md
 * i18n mandate) — the avatar's `avatar_persona.language` MUST match the
 * project's configured language, not a static env default. Sourced by the
 * controller from `$project->language` (the same locale source PR3/D9 already
 * uses for the opening greeting) rather than read by `HeygenProvider` off the
 * session's `project` relation directly — this keeps the provider a pure
 * function of its two arguments (testable without a persisted Project row,
 * which `ProviderSmokeCheck`'s in-memory fake session does not have) and
 * matches the widening pattern every prior field on this DTO already follows.
 * Null ⇒ `HeygenProvider` falls back to `config('interview.heygen.language')`.
 *
 * REQ: QuestionContext DTO (C7a)
 * REQ: QuestionContext Carries Composed Prompt (C8 — task 4.2)
 * REQ: QuestionContext Carries a Composed Opening Greeting (PR3 — delta spec, interview-conversation)
 * REQ: QuestionContext Carries the Session Language (hotfix 0.22.1)
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
        // Hotfix 0.22.1: nullable trailing param — null preserves exact pre-hotfix
        // provider body (falls back to the platform config default language).
        public ?string $language = null,
        /**
         * D6 — 1-based ordinal of this competency in the project's order, and the
         * project's competency count. Threaded to the /start response so the client
         * never re-derives progress; client-side arithmetic on an empty competency
         * list is what truncated every interview to one question.
         *
         * NOT an identity with `questionIndex + 1` (interview-question-index-offset,
         * D6): `questionIndex` is PERSISTED and equals `position` verbatim;
         * `competencyOrdinal` is DERIVED per request and always dense. They coincide
         * on a dense, unreordered project but diverge whenever positions are sparse
         * or the project is reordered after a session already exists.
         */
        public ?int $competencyOrdinal = null,
        public ?int $totalCompetencies = null,
    ) {}
}
