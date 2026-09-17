<?php

declare(strict_types=1);

/**
 * Interview avatar completion-signal phrases (C7a follow-up — interview-frontend addendum).
 *
 * These are platform-default localized UX strings — institutional avatar chrome, NOT
 * per-tenant/BARS content. They are the SAME for every project of a given language.
 *
 * The frontend (C7b) consumes these as the SOLE source for HeyGen completion-signal
 * detection:
 *   - end_phrase   — spoken by the avatar to close an intermediate question.
 *   - final_phrase — spoken by the avatar to close the final question (thank-you).
 *
 * English is the platform default language (config app.fallback_locale), so these
 * strings are also the fallback for any project language without its own phrase file.
 *
 * `opening.*`: composed by App\Services\Conversation\OpeningTextComposer. An
 * operator-authored primary question is the opening, verbatim, so these are
 * not greetings in general:
 *   - `fallback` is spoken only when a competency has NO primary questions,
 *     which is reachable only while the interviewability gate is off. It is
 *     that competency's only question, so it ends in a question: an opening
 *     that asks nothing leaves the avatar waiting for a turn that never comes.
 *     `:competency` is the competency's display name.
 *   - `retry_authored` wraps the question (`:question`) the retry re-asks in
 *     an apology for a failure on our side.
 *
 * Interim/replaceable wording — changing it is a lang-file +
 * `conversation.prompt_version` bump, never a provider wire-contract change.
 */
return [
    'end_phrase' => "Let's move on to the next question.",
    'final_phrase' => 'Thank you for your time.',

    'opening' => [
        'fallback' => "Let's talk about :competency. Tell me about one specific episode from your work where this came up: what happened?",
        'retry_authored' => "Sorry, we had a technical problem on our side. Let's start over. :question",
    ],
];
