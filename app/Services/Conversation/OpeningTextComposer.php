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
 * Interim/replaceable by design (delta spec "QuestionContext Carries a Composed
 * Opening Greeting"): a locale-keyed template built on the competency's display
 * name, versioned with the SAME `conversation.prompt_version` the system prompt
 * uses (one version, both strings ship together — no second version string).
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
     *
     * @throws \InvalidArgumentException When $variant is not a known variant.
     */
    public function compose(string $variant, string $competencyName, string $locale): ComposedOpening
    {
        if (! in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException("OpeningTextComposer: unknown variant [{$variant}].");
        }

        $key = "interview.opening.{$variant}";
        $fallback = (string) config('app.fallback_locale');

        // Lang::has() checks the EXACT locale (no implicit fallback), matching
        // resolveCompletionPhrases()'s resolution rule — a missing/unknown locale
        // deterministically falls back to the platform default.
        $resolvedLocale = Lang::has($key, $locale) ? $locale : $fallback;

        $text = (string) Lang::get($key, ['competency' => $competencyName], $resolvedLocale);
        // Refused when blank, matching SystemPromptComposer: `(string) null` is
        // `''`, which would stamp an empty version onto the interview just as
        // silently as a stale literal would stamp a wrong one.
        $version = trim((string) config('conversation.prompt_version'));

        if ($version === '') {
            throw new CompositionException(
                'conversation.prompt_version is not configured. Every composed opening is '
                .'stamped with it, and an evaluation carrying a blank version cannot be '
                .'traced back to the prompt that produced it.',
            );
        }

        return new ComposedOpening($text, $version);
    }
}
