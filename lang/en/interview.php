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
 * `opening.*` (PR3, design D9): the avatar's spoken opening greeting per variant,
 * composed by App\Services\Conversation\OpeningTextComposer. `:competency` is
 * interpolated with the competency's display name. Interim/replaceable wording —
 * changing it is a lang-file + `conversation.prompt_version` bump, never a
 * provider wire-contract change.
 */
return [
    'end_phrase' => "Let's move on to the next question.",
    'final_phrase' => 'Thank you for your time.',

    'opening' => [
        'first' => "Hi, and welcome! Let's start by talking about :competency.",
        'next' => "Great, let's move on and talk about :competency.",
        'resume' => "Let's pick up where we left off, talking about :competency.",
        'retry' => 'Sorry, we had a technical problem on our side. Let us start :competency again.',
    ],
];
