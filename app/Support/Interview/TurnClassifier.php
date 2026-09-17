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
 * CONSERVATIVE, NOT LENIENT. A primary is matched once and only in order:
 * the pointer advances only when a turn ends with the NEXT unmatched primary,
 * and the classifier never guesses which primary a turn was "probably"
 * answering. A TTS/ASR round-trip that rewords a primary produces a false
 * `follow_up` (OQ-C, framework-catalogue-authoring tasks.md) — it
 * OVER-reports, and never silently promotes a hidden question to a primary.
 *
 * A VERBATIM RE-ASK IS STILL `primary`, AND DOES NOT ADVANCE. A resumed
 * competency opens by re-asking a primary word for word (the pending one, or
 * the last one when every primary was already asked). That turn ends with an
 * already-matched primary, so it is classified `primary` — it is the
 * operator's question, not an invented one — but it does not move the
 * pointer. Because of that, "how many primaries were asked" is NOT the number
 * of `primary` rows: `matchedCount()` replays the session's `primary` rows in
 * order and advances only on the rows that matched the next unmatched entry,
 * which makes the count deterministic however many re-asks the transcript
 * holds.
 *
 * QUERIED AT WRITE TIME BY DEFAULT, KEEPS NO STATE OF ITS OWN. `classify()`
 * replays this session's persisted `primary` rows unless the caller supplies
 * `$matchedCount`. A caller writing several avatar turns in one batch (the
 * provider harvest) passes the running count itself and increments it only
 * when `advances()` says the row moved the pointer.
 */
final class TurnClassifier
{
    /**
     * Classify one avatar turn against `$session->primary_questions`.
     *
     * `primary` when `$text` ENDS WITH the next unmatched primary, or with
     * an already-matched one (a verbatim re-ask), under normalisation.
     * `follow_up` otherwise, including when the session has no
     * primary-question snapshot.
     *
     * @param  int|null  $matchedCount  The number of primaries already
     *                                  matched for this session, when the
     *                                  caller already knows it (a batch
     *                                  classifying several rows in one pass —
     *                                  see `InterviewController::
     *                                  insertUtterances()`). `null` (the
     *                                  default) replays it via
     *                                  `matchedCount()`, which is correct for
     *                                  a batch ONLY if each row is persisted
     *                                  before the next is classified.
     */
    public function classify(InterviewSession $session, string $text, ?int $matchedCount = null): string
    {
        $primaries = self::primaries($session);

        if ($primaries === []) {
            return 'follow_up';
        }

        $matchedCount ??= $this->matchedCount($session);
        $normalized = self::normalize($text);

        if (self::endsWithPrimary($normalized, $primaries, $matchedCount)) {
            return 'primary';
        }

        // A verbatim re-ask of a primary that was already matched.
        for ($index = 0; $index < min($matchedCount, count($primaries)); $index++) {
            if (self::endsWithPrimary($normalized, $primaries, $index)) {
                return 'primary';
            }
        }

        return 'follow_up';
    }

    /**
     * Whether `$text` asks the NEXT unmatched primary, i.e. moves the pointer.
     * A re-ask of an already-matched primary classifies as `primary` but
     * returns false here.
     */
    public function advances(InterviewSession $session, string $text, int $matchedCount): bool
    {
        return self::endsWithPrimary(self::normalize($text), self::primaries($session), $matchedCount);
    }

    /**
     * The number of distinct primaries already asked in this session: the
     * persisted `primary` avatar rows replayed in transcript order, advancing
     * only on the rows that asked the next unmatched primary.
     */
    public function matchedCount(InterviewSession $session): int
    {
        $primaries = self::primaries($session);

        if ($primaries === []) {
            return 0;
        }

        $texts = Utterance::where('interview_session_id', $session->id)
            ->where('speaker', 'avatar')
            ->where('turn_kind', 'primary')
            ->orderBy('ts')
            ->orderBy('id')
            ->pluck('text');

        $matched = 0;

        foreach ($texts as $text) {
            if (self::endsWithPrimary(self::normalize((string) $text), $primaries, $matched)) {
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * @return list<string>
     */
    private static function primaries(InterviewSession $session): array
    {
        return $session->primary_questions ?? [];
    }

    /**
     * @param  list<string>  $primaries
     */
    private static function endsWithPrimary(string $normalizedText, array $primaries, int $index): bool
    {
        if (! isset($primaries[$index])) {
            return false;
        }

        $primary = self::normalize($primaries[$index]);

        return $primary !== '' && str_ends_with($normalizedText, $primary);
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
