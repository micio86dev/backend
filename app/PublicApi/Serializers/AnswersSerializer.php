<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Utterance;
use App\Support\PublicApi\SessionTurnReplay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * Public-safe `Answer[]` shape for the BEAI Public API (`/v1`) — SPEC.md
 * §3.3 "Convenience grouping of the transcript per competency/question. No
 * scores.", `public-api/openapi.yaml`'s `Answer` schema, exercised by
 * `GET /v1/interviews/{id}/answers`. Same read gate as the transcript (the
 * caller's responsibility, not this class's — see `TranscriptSerializer`'s
 * own docblock).
 *
 * G-37 (DECISIONS-NEEDED.md): one answer entry per primary question the
 * session's `App\Support\PublicApi\SessionTurnReplay` says genuinely
 * ADVANCES the pointer — a verbatim re-ask of an already-matched primary
 * (a resume) never opens a SECOND entry for the same question. `question_text`
 * is read verbatim from `InterviewSession.primary_questions[question_index]`
 * — the AUTHORED question, never the avatar turn's own (possibly
 * retry-wrapped) text. `answer_text` joins every CANDIDATE turn between one
 * primary-advancing avatar turn and the next with a single space — an
 * avatar follow-up turn contributes no text of its own but does not close
 * the answer either, matching SPEC.md's "answer_text joins the candidate
 * turns up to the next primary avatar turn" read as "the next NEW primary".
 *
 * **Judgement call** (undecided by SPEC.md/DECISIONS-NEEDED.md, reported
 * rather than silently resolved): `started_at_seconds` is the delta of the
 * PRIMARY QUESTION's own utterance timestamp from `participant.started_at`
 * — when this Q&A round began, defined and orderable even for a question
 * the candidate never answered. `answer_duration_seconds` spans only the
 * CANDIDATE's own turns in the bucket (last candidate `ts` minus first),
 * `null` when the candidate never answered — it measures how long the
 * candidate took to respond, not how long the avatar spoke.
 */
final class AnswersSerializer
{
    /**
     * @return list<array{competency_code: string, question_index: int, question_text: string, answer_text: string, started_at_seconds: float|null, answer_duration_seconds: float|null}>
     */
    public static function toArray(Participant $participant): array
    {
        $sessions = InterviewSession::where('participant_id', $participant->id)
            // See TranscriptSerializer::toArray()'s identical eager-load
            // constraint for why this is typed bare `Relation` and ordered
            // via `getQuery()`.
            ->with(['utterances' => fn (Relation $relation): Builder => $relation->getQuery()->orderBy('ts')->orderBy('id')])
            ->orderBy('question_index')
            ->orderBy('id')
            ->get();

        $answers = [];

        foreach ($sessions as $session) {
            $answers = array_merge($answers, self::answersForSession($session, $participant));
        }

        return $answers;
    }

    /**
     * @return list<array{competency_code: string, question_index: int, question_text: string, answer_text: string, started_at_seconds: float|null, answer_duration_seconds: float|null}>
     */
    private static function answersForSession(InterviewSession $session, Participant $participant): array
    {
        $primaries = $session->primary_questions ?? [];
        $answers = [];

        /** @var array{questionIndex: int, questionText: string, questionTs: CarbonImmutable, answerParts: list<string>, firstCandidateTs: CarbonImmutable|null, lastCandidateTs: CarbonImmutable|null}|null $current */
        $current = null;

        foreach (SessionTurnReplay::forSession($session) as $entry) {
            /** @var Utterance $utterance */
            $utterance = $entry['utterance'];

            if ($entry['advances_primary']) {
                if ($current !== null) {
                    $answers[] = self::finalizeAnswer($session, $current, $participant);
                }

                $current = [
                    'questionIndex' => $entry['question_index'],
                    'questionText' => $primaries[$entry['question_index']] ?? $utterance->text,
                    'questionTs' => $utterance->ts,
                    'answerParts' => [],
                    'firstCandidateTs' => null,
                    'lastCandidateTs' => null,
                ];

                continue;
            }

            if ($current === null || $utterance->speaker !== 'candidate') {
                continue;
            }

            $current['answerParts'][] = $utterance->text;
            $current['firstCandidateTs'] ??= $utterance->ts;
            $current['lastCandidateTs'] = $utterance->ts;
        }

        if ($current !== null) {
            $answers[] = self::finalizeAnswer($session, $current, $participant);
        }

        return $answers;
    }

    /**
     * @param  array{questionIndex: int, questionText: string, questionTs: CarbonImmutable, answerParts: list<string>, firstCandidateTs: CarbonImmutable|null, lastCandidateTs: CarbonImmutable|null}  $current
     * @return array{competency_code: string, question_index: int, question_text: string, answer_text: string, started_at_seconds: float|null, answer_duration_seconds: float|null}
     */
    private static function finalizeAnswer(InterviewSession $session, array $current, Participant $participant): array
    {
        $startedAt = $participant->started_at;

        return [
            'competency_code' => $session->competency_code,
            'question_index' => $current['questionIndex'],
            'question_text' => $current['questionText'],
            'answer_text' => implode(' ', $current['answerParts']),
            'started_at_seconds' => $startedAt === null
                ? null
                : self::secondsBetween($current['questionTs'], $startedAt),
            'answer_duration_seconds' => $current['firstCandidateTs'] === null || $current['lastCandidateTs'] === null
                ? null
                : self::secondsBetween($current['lastCandidateTs'], $current['firstCandidateTs']),
        ];
    }

    /**
     * `$later - $earlier`, in seconds, sub-second precision preserved,
     * SIGNED (never `abs()`) — positive when `$later` is genuinely after
     * `$earlier`. Carbon's own `diffInSeconds($other, false)` sign
     * convention has flipped across versions and is easy to get backwards;
     * computed explicitly from each side's own Unix timestamp instead of
     * trusting it.
     */
    private static function secondsBetween(CarbonImmutable|Carbon $later, CarbonImmutable|Carbon $earlier): float
    {
        $laterMicro = ((float) $later->getTimestamp()) + ($later->micro / 1_000_000);
        $earlierMicro = ((float) $earlier->getTimestamp()) + ($earlier->micro / 1_000_000);

        return $laterMicro - $earlierMicro;
    }
}
