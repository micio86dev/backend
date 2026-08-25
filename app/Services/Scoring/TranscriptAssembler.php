<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\ScoringCorpora;
use App\Models\InterviewSession;

/**
 * Assembles the two scoring corpora for a participant (evaluator-evidence-and-rigor D-1).
 *
 * ⚠️ THE OLD INVARIANT IS REPEALED. This class used to state that ONE string was
 * passed both to the LLM prompt and to `ExcerptValidator`. It no longer is, and
 * reintroducing that equality would reintroduce a real defect: under it, an
 * excerpt quoting the interviewer's own question validated as evidence about the
 * candidate. The two roles pull in opposite directions —
 *
 *   the PROMPT corpus must GROW: the whole interview, both speakers. Evidence a
 *   candidate gave while answering a different competency's question is still
 *   evidence, and a BEI answer read without its question is often unscoreable.
 *
 *   the VALIDATION corpus must SHRINK: candidate-spoken text only. Evidence
 *   about the candidate is what the candidate said.
 *
 * — and the invariant that replaces equality is a SUBSET relation: every
 * candidate utterance in `validation` is also in `prompt`. That is strictly
 * stricter than what it replaces; no excerpt passes the new rule that failed the
 * old one. Both corpora are produced from a SINGLE ordered pass over a SINGLE
 * in-memory collection, so the invariant holds by construction rather than by
 * convention.
 *
 * Ordering (determinism-critical):
 *   Sessions:   orderBy('id').
 *               NOT `started_at`: it is nullable, and NULL placement is a
 *               property of the query rather than of the data, so a future query
 *               rewrite could silently reorder the transcript. `id` is monotonic,
 *               never null, and equals interview order because a session row is
 *               created as the interview reaches that competency.
 *   Utterances: orderBy('ts')->orderBy('id') — the dual sort is preserved
 *               verbatim. `ts` alone is NOT unique: HeyGen bulk-replace produces
 *               utterances sharing a timestamp, and the autoincrement id is the
 *               stable secondary sort.
 *
 * Speaker matching is CASE-INSENSITIVE, deliberately. Both current write paths
 * normalise to lowercase — `UtteranceController` validates `in:candidate,avatar`
 * and `HeygenProvider` maps provider roles through a `match` that THROWS on
 * anything unrecognized — so an exact comparison would be correct for every row
 * written today. It is the rows NOT written today that decide this: a single
 * historic `Candidate` would be silently dropped from the validation corpus,
 * every excerpt would then fail verbatim validation, and the participant would
 * score -1 across the board with nothing anywhere saying why.
 *
 * The two failure modes are not symmetric. Case-folding cannot mistake one
 * speaker for another — `Candidate` and `candidate` are unambiguously the same
 * person. Case-sensitivity can lose an entire interview's evidence without a
 * sound. Any value that is genuinely neither speaker is still excluded, and that
 * degrades to a visible per-indicator `excerpt_unverifiable`, not a silent zero.
 *
 * REQ: TranscriptAssembler (C9 D3 CW3, split corpora D-1/D-2/D-3)
 */
final class TranscriptAssembler
{
    private const SPEAKER_CANDIDATE = 'candidate';

    private const TARGET_BEGIN = '=== TARGET COMPETENCY %s — PRIMARY EVIDENCE BEGINS ===';

    private const TARGET_END = '=== TARGET COMPETENCY %s — PRIMARY EVIDENCE ENDS ===';

    /**
     * Assemble both corpora for a participant, delimiting the target competency.
     *
     * @param  int  $participantId  The participant whose whole interview is assembled.
     * @param  string  $targetCompetencyCode  The competency currently being scored.
     */
    public function assembleForParticipant(int $participantId, string $targetCompetencyCode): ScoringCorpora
    {
        // withoutGlobalScopes: the scoring job runs outside a tenant request
        // context. Cross-tenant isolation is enforced by the participantId, which
        // C6 mints per organization.
        $sessions = InterviewSession::withoutGlobalScopes()
            ->where('participant_id', $participantId)
            ->orderBy('id')
            ->with(['utterances' => static fn ($query) => $query
                ->withoutGlobalScopes()
                ->orderBy('ts')
                ->orderBy('id'),
            ])
            ->get();

        $promptLines = [];
        $validationLines = [];

        foreach ($sessions as $session) {
            $utterances = $session->utterances;

            // Markers are emitted only around a target segment that HAS content.
            // Empty markers would announce a primary-evidence block that does not
            // exist, which is worse than announcing nothing.
            $isTarget = $session->competency_code === $targetCompetencyCode
                && $utterances->isNotEmpty();

            if ($isTarget) {
                $promptLines[] = sprintf(self::TARGET_BEGIN, $targetCompetencyCode);
            }

            foreach ($utterances as $utterance) {
                $promptLines[] = "{$utterance->speaker}: {$utterance->text}";

                // No speaker prefix and no markers here: the validation corpus is
                // candidate speech and nothing else, so a quoted prefix or a
                // quoted delimiter fails for the same reason invented text does,
                // through the same code path, with no special case.
                if (strtolower(trim((string) $utterance->speaker)) === self::SPEAKER_CANDIDATE) {
                    $validationLines[] = $utterance->text;
                }
            }

            if ($isTarget) {
                $promptLines[] = sprintf(self::TARGET_END, $targetCompetencyCode);
            }
        }

        return new ScoringCorpora(
            prompt: implode("\n", $promptLines),
            validation: implode("\n", $validationLines),
        );
    }
}
