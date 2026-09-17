<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\DTOs\Conversation\ComposedOpening;
use App\Exceptions\Conversation\CompositionException;
use Illuminate\Support\Facades\Lang;

/**
 * Composes the avatar's spoken opening greeting at /start time (PR3, design D9/D11).
 *
 * A SIBLING of SystemPromptComposer, NEVER inside it: SystemPromptComposer is a
 * pure function over BARS anchor text (scoring instructions, never revealed
 * verbatim); folding the greeting into it would put anchor text within reach of
 * the one string actually SPOKEN aloud to the candidate. This class has no
 * dependency on BarsIndicatorLoader or any BARS model — the anti-leak guarantee
 * holds by construction, not by discipline.
 *
 * The opening is the operator's primary question, verbatim, for `first`,
 * `next` and `resume` — the controller decides WHICH primary (primary 1 on a
 * fresh start, the pending one on a resume). `retry` wraps it in an apology,
 * because those words explain a failure on our side. When there is no
 * authored question — a competency with zero primaries, reachable only while
 * the interviewability gate is off — every variant speaks the one gate-off
 * fallback, `interview.opening.fallback`, which is a question in its own
 * right so the avatar never opens on a dead turn.
 *
 * Every opening is versioned with the SAME `conversation.prompt_version` the
 * system prompt uses (one version, both strings ship together — no second
 * version string).
 * Replacing the wording later is a lang-file + version bump, never a wire-contract
 * change: only this class and `HeygenProvider`/`TavusProvider`'s `opening_text`/
 * `custom_greeting` fields know a greeting exists at all.
 *
 * Templates live in `lang/{it,en}/interview.php` (NOT `config/`): `config:cache`
 * freezes config, and config is not the i18n surface — mirrors
 * `InterviewController::resolveCompletionPhrases()`, the identical
 * `Lang::`+fallback pattern two methods away in the same controller.
 *
 * REQ: OpeningTextComposer (PR3 — design D9)
 * REQ: QuestionContext Carries a Composed Opening Greeting (delta spec, interview-conversation)
 */
final class OpeningTextComposer
{
    /**
     * Valid opening variants — selected by the CONTROLLER (design D9), never here:
     *   'first'  — the participant's very first competency of the interview.
     *   'next'   — a subsequent (non-resumed) competency.
     *   'resume' — a fresh provider session re-issued for an in-progress
     *              competency; the caller passes the primary to re-ask.
     *   'retry'  — a competency that ended in `error` and is being OFFERED AGAIN
     *              (interview-continuous-flow, D10). Its apology is the only
     *              wording that differs from the other variants.
     */
    private const VARIANTS = ['first', 'next', 'resume', 'retry'];

    /**
     * Compose the opening greeting for a single competency.
     *
     * Pure — no HTTP, no DB, no LLM. Same inputs always produce the same output.
     *
     * @param  string  $variant  One of 'first' | 'next' | 'resume' | 'retry'.
     * @param  string  $competencyName  The competency's display name, in the target locale
     *                                  (caller resolves translation — this class only interpolates).
     * @param  string  $locale  The project's language (design D9 — matches SystemPromptComposer).
     * @param  string|null  $authoredQuestion  The operator's primary question this opening
     *                                         asks. Blank after `trim()` is treated as absent
     *                                         and the gate-off fallback is spoken instead.
     *
     * @throws \InvalidArgumentException When $variant is not a known variant.
     * @throws CompositionException When `conversation.prompt_version` is unset or blank —
     *                              raised on every path, and it decides an HTTP 422 at
     *                              the controller layer.
     */
    public function compose(
        string $variant,
        string $competencyName,
        string $locale,
        ?string $authoredQuestion = null,
    ): ComposedOpening {
        if (! in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException("OpeningTextComposer: unknown variant [{$variant}].");
        }

        $question = $authoredQuestion === null ? '' : trim($authoredQuestion);

        // `Lang::get()` falls back to `app.fallback_locale` on its own, so an
        // unknown or partial locale deterministically lands on the platform
        // default.
        if ($question === '') {
            $question = (string) Lang::get(
                'interview.opening.fallback',
                ['competency' => $competencyName],
                $locale,
            );
        }

        $text = $variant === 'retry'
            ? (string) Lang::get('interview.opening.retry_authored', ['question' => $question], $locale)
            : $question;

        return new ComposedOpening($text, $this->resolveVersion());
    }

    /**
     * Refused when blank, matching SystemPromptComposer: `(string) null` is
     * `''`, which would stamp an empty version onto the interview just as
     * silently as a stale literal would stamp a wrong one.
     *
     * A greeting whose version disagrees with the system prompt's is exactly
     * the untraceable evaluation the check exists to prevent.
     *
     * @throws CompositionException
     */
    private function resolveVersion(): string
    {
        $version = trim((string) config('conversation.prompt_version'));

        if ($version === '') {
            throw new CompositionException(
                'conversation.prompt_version is not configured. Every composed opening is '
                .'stamped with it, and an evaluation carrying a blank version cannot be '
                .'traced back to the prompt that produced it.',
            );
        }

        return $version;
    }
}
