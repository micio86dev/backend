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
 * Adding a new language = adding lang/{locale}/interview.php. Missing languages fall
 * back to the platform default language (config app.fallback_locale) at resolution time.
 *
 * `opening.*`: composed by App\Services\Conversation\OpeningTextComposer. An
 * operator-authored primary question is the opening, verbatim:
 *   - `fallback` is spoken only when a competency has NO primary questions,
 *     which is reachable only while the interviewability gate is off. It is
 *     that competency's only question, so it ends in a question.
 *     `:competency` is the competency's display name.
 *   - `retry_authored` wraps the question (`:question`) the retry re-asks in
 *     an apology for a failure on our side.
 */
return [
    'end_phrase' => 'Passiamo alla prossima domanda.',
    'final_phrase' => 'Grazie per il tuo tempo.',

    'opening' => [
        'fallback' => 'Parliamo di :competency. Raccontami un episodio specifico del tuo lavoro in cui questo è emerso: cosa è successo?',
        'retry_authored' => 'Scusami, c\'è stato un problema tecnico da parte nostra. Riprendiamo da capo. :question',
    ],
];
