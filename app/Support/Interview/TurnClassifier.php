<?php

declare(strict_types=1);

namespace App\Support\Interview;

use App\Models\InterviewSession;
use App\Models\Utterance;

/**
 * Classifies one avatar transcript turn as `primary` or `follow_up`
 * (framework-catalogue-authoring PR7, design D8).
 *
 * WHY THIS EXISTS. "No hidden questions" (interview-conversation,
 * `Authored Primary Questions Are The Complete Primary Set`) is asserted in
 * the system prompt, but a prompt instruction is not an audit — it says what
 * the model was TOLD, not what it DID. This class is the audit: it marks
 * every avatar turn against the session's own primary-question snapshot, so
 * "did the avatar ask exactly the authored primaries, and only invent
 * follow-ups" is a query over the transcript, not a claim about the prompt.
 *
 * EXACT SUFFIX, NEVER A SIMILARITY SCORE. An avatar turn is `primary` only
 * when the next UNMATCHED entry of `$session->primary_questions` is the
 * turn's OWN FINAL question — VERBATIM, at the very end of the turn under
 * normalisation (casefold + whitespace-collapse + trailing-punctuation
 * strip) — nothing looser. This is deliberately a trailing match, not
 * equality: `OpeningTextComposer`'s `retry` variant
 * (`interview.opening.retry_authored`) wraps the authored primary in a fixed
 * apology template — "Sorry, we had a technical problem on our side. Let's
 * start over. :question" — so the primary's exact text is embedded verbatim
 * at the END of a longer sentence, never the whole turn. Equality would
 * misclassify every re-offered competency's opening as `follow_up` and then
 * stall on that same primary for the rest of the competency, since "next
 * unmatched" never advances. A trailing match costs nothing on `first`/
 * `next`, where the turn already equals the primary exactly (a string
 * trivially ends with itself) — this is a strict widening of equality, not a
 * fuzzy replacement for it.
 *
 * NOT PLAIN CONTAINMENT (Z23, R3-turn-classifier-substring-false-primary,
 * REQUIRED BEFORE ARCHIVE — fixed): a bare `str_contains()` also matched a
 * follow-up that merely QUOTES the next unmatched primary somewhere in the
 * middle of its own text — "we may later ask: :primary. But first, what
 * about..." — without actually asking it, because the turn keeps going
 * afterward with substantive content of its own. Requiring the primary to be
 * the turn's OWN trailing content, not merely somewhere inside it, rejects
 * that false positive while still accepting the retry wrapper above (the
 * primary IS the turn's last clause there) and any other prefix-only
 * wrapping. A fuzzy threshold would silently decide, per session, how much
 * rewording still counts as "the same question"; an exact trailing match
 * after normalisation decides nothing and hides nothing.
 *
 * THE REFERENCE IS FIXED ONCE A TURN EXISTS TO COMPARE AGAINST IT, NOT LIVE
 * `project_questions`. This is not the text comparison `project-config` (D9)
 * rejects for provenance — the difference is what is being compared to. D9
 * refuses to infer provenance from a catalogue default because the default
 * can change later, silently flipping the comparison's answer. Here the
 * reference is `interview_sessions.primary_questions`: `InterviewController`
 * may refresh it for a FRESH prompt composition (a resume or a retry
 * re-offer), but only while the session's transcript is still empty — the
 * instant a turn is recorded against it, it stops moving under the
 * comparison, which is the property this class actually depends on.
 *
 * CONSERVATIVE, NOT LENIENT. "Next unmatched" means a primary can only be
 * matched once and only in order — the classifier never re-matches an
 * earlier primary, and it never guesses which primary a turn was "probably"
 * answering. A turn that does not match the next unmatched primary is
 * `follow_up`, even when it happens to repeat an EARLIER primary's wording.
 * This is the direction OQ-C (framework-catalogue-authoring tasks.md)
 * accepts as a disclosed residual: a TTS/ASR round-trip that rewords a
 * primary produces a false `follow_up` and, downstream, a false transcript-
 * audit violation — it OVER-reports, and never silently reclassifies a
 * hidden primary as a follow-up instead.
 *
 * QUERIED AT WRITE TIME BY DEFAULT, KEEPS NO STATE OF ITS OWN. `classify()`
 * counts this session's already-persisted `primary`-marked avatar turns to
 * find the next unmatched entry unless the caller supplies `$matchedCount`
 * explicitly. A caller writing several avatar turns in one batch (the
 * provider harvest) MAY pass the running count itself — tracked in memory
 * and incremented after each `primary` classification — rather than re-query
 * it before every row; either way, the count must reflect every avatar turn
 * classified so far in the SAME batch, persisted or not.
 */
final class TurnClassifier
{
    /**
     * Classify one avatar turn against `$session->primary_questions`.
     *
     * `follow_up` when the session has no primary-question snapshot, when
     * every primary is already matched, or when `$text` does not END WITH the
     * next unmatched primary, verbatim, under normalisation.
     *
     * @param  int|null  $matchedCount  The number of primaries already
     *                                  matched for this session, when the
     *                                  caller already knows it (a batch
     *                                  classifying several rows in one pass —
     *                                  see `InterviewController::
     *                                  insertUtterances()`). `null` (the
     *                                  default) queries it fresh, which is
     *                                  always correct for a single live turn
     *                                  and remains correct for a batch ONLY
     *                                  if each row is persisted before the
     *                                  next is classified.
     */
    public function classify(InterviewSession $session, string $text, ?int $matchedCount = null): string
    {
        $primaries = $session->primary_questions ?? [];

        if ($primaries === []) {
            return 'follow_up';
        }

        $matchedCount ??= Utterance::where('interview_session_id', $session->id)
            ->where('speaker', 'avatar')
            ->where('turn_kind', 'primary')
            ->count();

        if ($matchedCount >= count($primaries)) {
            return 'follow_up';
        }

        $next = self::normalize((string) $primaries[$matchedCount]);

        if ($next === '') {
            return 'follow_up';
        }

        return str_ends_with(self::normalize($text), $next) ? 'primary' : 'follow_up';
    }

    /**
     * Casefold + whitespace-collapse + trailing-punctuation-strip. Not a
     * similarity score, not a word list — this is the entire comparison.
     */
    private static function normalize(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        // Z19 (R3, framework-catalogue-authoring, optional — done, cheap and
        // safe): `rtrim()`'s character-mask argument is BYTE-WISE, not
        // multibyte-aware — it strips individual BYTES matching the mask,
        // which corrupts a multi-byte punctuation character (`。！？`, each
        // 3 UTF-8 bytes) whenever one of ITS OWN bytes happens to collide
        // with a byte from another character in the mask or in the trimmed
        // string. A `preg_replace()` with the `/u` (UTF-8) modifier treats
        // the class as whole CHARACTERS instead.
        return preg_replace('/[.!?,;:。！？]+$/u', '', $normalized) ?? $normalized;
    }
}
