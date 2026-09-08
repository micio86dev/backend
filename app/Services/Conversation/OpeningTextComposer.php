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
 * TWO shapes, not one. When the operator authored a question for this
 * competency, `first`/`next` return THAT question verbatim — no template, no
 * competency name, no welcome (ratified 2026-09-08). Otherwise, and always for
 * `resume`, it is a locale-keyed template built on the competency's display
 * name. `retry` is the middle case: its apology wraps the authored question
 * when there is one, because those words explain a failure on our side rather
 * than greeting anybody.
 *
 * Both shapes are versioned with the SAME `conversation.prompt_version` the
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
     *   'resume' — a fresh provider session re-issued for an in-progress competency.
     *   'retry'  — a competency that ended in `error` and is being OFFERED AGAIN
     *              (interview-continuous-flow, D10). Distinct from 'resume': that
     *              one continues a conversation still in progress, this one starts
     *              a competency over after a failure on OUR side. Without it the
     *              avatar asks the same question twice with no explanation, which
     *              reads to the candidate as not having been listened to.
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
     * @param  string|null  $authoredQuestion  The operator's own first question for this
     *                                         competency, when there is one. Blank after
     *                                         `trim()` is treated as absent and falls back
     *                                         to the template. `first`/`next` return it
     *                                         verbatim; `retry` wraps it in its apology;
     *                                         `resume` ignores it entirely, because that
     *                                         variant continues an episode already under
     *                                         way and re-asking would discard what the
     *                                         candidate has already said.
     *
     * @throws \InvalidArgumentException When $variant is not a known variant.
     * @throws CompositionException When `conversation.prompt_version` is unset or blank —
     *                              raised on BOTH shapes, and it decides an HTTP 422 at
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

        $authored = $authoredQuestion === null ? '' : trim($authoredQuestion);

        // The AUTHORED question, when there is one (ratified 2026-09-08).
        //
        // Reported from production: an operator wrote their own questions for a
        // competency and the avatar opened with "Parliamo di problem solving…
        // raccontami un episodio", a sentence they had never written. Their
        // questions were reaching the system prompt as mandatory the whole
        // time; the trouble was ORDER. The template already asked a generic
        // question, so the first thing any candidate ever heard was never the
        // operator's own.
        //
        // `first`/`next` therefore drop the template entirely — no welcome, no
        // competency name, just the question as written. `retry` keeps its
        // apology, which explains a failure on OUR side rather than greeting
        // anyone. `resume` keeps its template unconditionally: that variant
        // continues an episode already in progress, and re-asking the opening
        // question there would discard what the candidate has already said.
        if ($authored !== '' && $variant !== 'resume') {
            $version = $this->resolveVersion();

            if ($variant === 'retry') {
                // Locale resolution is `Lang::get()`'s own, and this used to try to do
                // it twice. The removed guard read
                // `Lang::has($key, $locale) ? $locale : $fallback` under a comment
                // claiming `has()` checks the EXACT locale — the inverse of what
                // Laravel does: `Translator::has($key, $locale, $fallback = true)`
                // defaults that third argument to TRUE, so
                // `Lang::has('interview.opening.first', 'pt')` answers true with no
                // `lang/pt/` on disk at all (verified).
                //
                // It was also inert. `Lang::get()` falls back to
                // `app.fallback_locale` by itself, so mutating the guard away changed
                // no output and failed no test — a guard no mutation can break is not
                // a guard, it is a wasted lookup with a false comment on top.
                return new ComposedOpening(
                    (string) Lang::get('interview.opening.retry_authored', ['question' => $authored], $locale),
                    $version,
                );
            }

            return new ComposedOpening($authored, $version);
        }

        // Same resolution as above: `Lang::get()` falls back to
        // `app.fallback_locale` on its own, so an unknown or partial locale
        // deterministically lands on the platform default.
        $text = (string) Lang::get(
            "interview.opening.{$variant}",
            ['competency' => $competencyName],
            $locale,
        );

        return new ComposedOpening($text, $this->resolveVersion());
    }

    /**
     * Refused when blank, matching SystemPromptComposer: `(string) null` is
     * `''`, which would stamp an empty version onto the interview just as
     * silently as a stale literal would stamp a wrong one.
     *
     * Extracted so the authored-question path above stamps the IDENTICAL
     * version as the template path. Two copies of this would be two places to
     * forget, and a greeting whose version disagrees with the system prompt's
     * is exactly the untraceable evaluation the check exists to prevent.
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
