<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Models\InterviewSession;
use App\Models\Utterance;
use App\Support\Interview\TurnClassifier;
use Illuminate\Support\Collection;

/**
 * Replays one `InterviewSession`'s utterances in transcript order and
 * derives, for each one, the G-37 public `question_index` ordinal — the
 * 0-based position of the primary question within the competency the
 * candidate turns answer and the avatar follow-ups belong to (DECISIONS-
 * NEEDED.md G-37).
 *
 * Shared by `App\PublicApi\Serializers\TranscriptSerializer` (every turn
 * keeps its own `question_index`) and `App\PublicApi\Serializers\
 * AnswersSerializer` (a NEW answer starts exactly where this replay says a
 * primary turn ADVANCES the pointer) — one algorithm, so the two public
 * surfaces can never disagree about which question a turn belongs to.
 *
 * Reuses `App\Support\Interview\TurnClassifier`'s own public API
 * (`classify()`/`advances()`) rather than re-implementing its normalisation
 * — never `Utterance.turn_kind` directly: that column records what the
 * WRITE-time classifier decided with whatever `$matchedCount` it held at
 * INSERT time, batched per request; this class re-derives the SAME facts
 * from a clean replay of the whole session, which is what a READ over the
 * full transcript needs to stay correct even if a future write path ever
 * changes how it batches `$matchedCount`.
 */
final class SessionTurnReplay
{
    /**
     * @return list<array{utterance: Utterance, question_index: int, advances_primary: bool}>
     */
    public static function forSession(InterviewSession $session): array
    {
        $classifier = new TurnClassifier;
        $primaries = $session->primary_questions ?? [];
        $matchedCount = 0;
        $result = [];

        /** @var Utterance $utterance */
        foreach (self::orderedUtterances($session) as $utterance) {
            if ($utterance->speaker !== 'avatar' || $primaries === []) {
                $result[] = [
                    'utterance' => $utterance,
                    'question_index' => max($matchedCount - 1, 0),
                    'advances_primary' => false,
                ];

                continue;
            }

            $kind = $classifier->classify($session, $utterance->text, $matchedCount);

            if ($kind !== 'primary') {
                $result[] = [
                    'utterance' => $utterance,
                    'question_index' => max($matchedCount - 1, 0),
                    'advances_primary' => false,
                ];

                continue;
            }

            if ($classifier->advances($session, $utterance->text, $matchedCount)) {
                $result[] = [
                    'utterance' => $utterance,
                    'question_index' => $matchedCount,
                    'advances_primary' => true,
                ];
                $matchedCount++;

                continue;
            }

            // A verbatim re-ask of an already-matched primary (TurnClassifier's
            // own docblock: "the pending one, or the last one when every
            // primary was already asked") — search which EXACT already-matched
            // index it ends with, rather than assuming it is always the most
            // recent one.
            $result[] = [
                'utterance' => $utterance,
                'question_index' => self::reAskedIndex($classifier, $session, $utterance->text, $matchedCount),
                'advances_primary' => false,
            ];
        }

        return $result;
    }

    /**
     * @return Collection<int, Utterance>
     */
    private static function orderedUtterances(InterviewSession $session): Collection
    {
        return $session->relationLoaded('utterances')
            ? $session->utterances->sortBy([['ts', 'asc'], ['id', 'asc']])->values()
            : $session->utterances()->orderBy('ts')->orderBy('id')->get();
    }

    private static function reAskedIndex(TurnClassifier $classifier, InterviewSession $session, string $text, int $matchedCount): int
    {
        for ($index = $matchedCount - 1; $index >= 0; $index--) {
            if ($classifier->advances($session, $text, $index)) {
                return $index;
            }
        }

        return max($matchedCount - 1, 0);
    }
}
