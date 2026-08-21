<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Utterance;
use App\Support\Admin\LifecycleReadGate;

/**
 * Builds the admin transcript for a Participant across ALL of their
 * InterviewSessions (C11 D6), welded to the partial-disclosure marker
 * (operator-participant-visibility D2).
 *
 * A participant has ONE InterviewSession per competency
 * (InterviewSession.php:48-49, ScoreEvaluationJob.php:335-338).
 * TranscriptAssembler (C9, TranscriptAssembler.php:34) takes a SINGLE
 * session and is genuinely reused elsewhere for that narrower purpose —
 * this class is NEW assembly logic, not a wrapper around it: it iterates
 * every session belonging to the participant.
 *
 * Reads InterviewSession/Utterance under the ambient TenantContext scope
 * (both extend TenantModel) — never withoutGlobalScopes() (arch-tested,
 * task 5.3).
 *
 * Ordering:
 *   - Sessions: `question_index` ASC, then `id` ASC (delivery order).
 *   - Utterances within each session: `ts` ASC, then `id` ASC — the same
 *     dual-sort discipline argued at TranscriptAssembler.php:11-14 (ts alone
 *     is not guaranteed unique).
 *
 * REQ: Evaluation Serializer Is Scoped, Not Copied From the Webhook Assembler
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md — transcript half)
 *      Partial Transcript Disclosure Marker
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */
final class AdminTranscriptSerializer
{
    public function __construct(
        private readonly LifecycleReadGate $gate = new LifecycleReadGate,
    ) {}

    public function serialize(Participant $participant): AdminTranscript
    {
        $sessions = InterviewSession::where('participant_id', $participant->id)
            ->with(['utterances' => fn ($query) => $query->orderBy('ts')->orderBy('id')])
            ->orderBy('question_index')
            ->orderBy('id')
            ->get();

        return new AdminTranscript(
            isPartial: $this->gate->isTranscriptPartial($participant->status),
            sessions: $sessions
                ->map(fn (InterviewSession $session): array => [
                    'session_id' => $session->id,
                    'competency_code' => $session->competency_code,
                    'question_index' => $session->question_index,
                    'utterances' => $session->utterances
                        ->map(fn (Utterance $utterance): array => [
                            'speaker' => $utterance->speaker,
                            'text' => $utterance->text,
                            // Carbon::toISOString() is declared ?string (returns
                            // null only for an invalid/unset date) — $utterance->ts
                            // itself is never null (DB NOT NULL constraint,
                            // 2026_07_20_100003_create_utterances_table.php:44).
                            'ts' => $utterance->ts->toISOString(),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        );
    }
}
