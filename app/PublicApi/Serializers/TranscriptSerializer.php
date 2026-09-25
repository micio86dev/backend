<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Utterance;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\SessionTurnReplay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Public-safe `Transcript` shape for the BEAI Public API (`/v1`) — SPEC.md
 * §3.3, `public-api/openapi.yaml`'s `Transcript` schema, exercised by
 * `GET /v1/interviews/{id}/transcript`.
 *
 * The read GATE (status `under_evaluation` or `completed`, else `409
 * transcript_not_ready` — `error` included, per G-15) is the CALLER's
 * responsibility (`App\Http\Controllers\PublicApi\InterviewController`),
 * never this class's: a serializer only shapes data it is handed, exactly
 * the same division `InterviewSerializer`/`ProjectSerializer` already draw.
 *
 * Turns are ordered "by competency session, then by utterance time" (the
 * contract's own `turns` description) — sessions by `question_index`
 * (the project's delivery position) then `id`, utterances within a session
 * by `ts` then `id`, mirroring `App\Services\Admin\AdminTranscriptSerializer`'s
 * identical ordering discipline. `index` is the turn's 0-based position in
 * the WHOLE flattened array, not per-session.
 *
 * `question_index` on every turn — avatar primary, avatar follow-up AND
 * candidate alike — is the G-37 ordinal `App\Support\PublicApi\
 * SessionTurnReplay` derives: the position of the primary question a turn
 * belongs to, shared with `AnswersSerializer` so the two surfaces can never
 * disagree.
 *
 * **Contract note.** SPEC.md's step 6 brief additionally describes
 * `started_at_seconds`/`ended_at_seconds` on each turn; the VENDORED
 * contract (`public-api/openapi.yaml`'s `Transcript.turns` schema) declares
 * only `{index, speaker, text, competency_code, question_index, ts}` — no
 * such fields exist there. This serializer matches the CONTRACT exactly
 * (SPEC.md §0 "contract governance": the vendored `openapi.yaml` is the
 * wire truth) rather than inventing two additional response fields no
 * schema declares. Reported as a judgement call, not silently resolved.
 */
final class TranscriptSerializer
{
    /**
     * @return array{interview_id: string, language: string, turns: list<array{index: int, speaker: string, text: string, competency_code: string, question_index: int, ts: string}>}
     */
    public static function toArray(Participant $participant): array
    {
        $sessions = InterviewSession::where('participant_id', $participant->id)
            // $relation is typed bare `Relation` and ordered via
            // `getQuery()`, not `HasMany`/`orderBy()` directly — the SAME
            // reasoning as `App\Http\Controllers\PublicApi\
            // InterviewController::projectEagerLoad()`'s own docblock: an
            // inline `with([...])` closure's parameter is inferred as the
            // wildcard `Relation<*, *, *>`, on which Larastan cannot
            // resolve a forwarded query-builder method without a concrete
            // `TRelatedModel`; `getQuery()` is an ORDINARILY declared
            // method, never a macro, so it needs no generic resolution.
            ->with(['utterances' => fn (Relation $relation): Builder => $relation->getQuery()->orderBy('ts')->orderBy('id')])
            ->orderBy('question_index')
            ->orderBy('id')
            ->get();

        $turns = [];
        $index = 0;

        foreach ($sessions as $session) {
            foreach (SessionTurnReplay::forSession($session) as $entry) {
                /** @var Utterance $utterance */
                $utterance = $entry['utterance'];

                $turns[] = [
                    'index' => $index,
                    'speaker' => $utterance->speaker,
                    'text' => $utterance->text,
                    'competency_code' => $session->competency_code,
                    'question_index' => $entry['question_index'],
                    // toIso8601String() (never toISOString(), declared
                    // ?string) — PHPStan --level=max flagged the nullable
                    // return even though `ts` is a NOT NULL column and this
                    // Carbon instance is never genuinely invalid; the
                    // non-nullable sibling method says the same thing
                    // honestly, with no ignore needed.
                    'ts' => $utterance->ts->toIso8601String(),
                ];

                $index++;
            }
        }

        return [
            'interview_id' => PublicId::encode($participant),
            'language' => (string) $participant->language,
            'turns' => $turns,
        ];
    }
}
