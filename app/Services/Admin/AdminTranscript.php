<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * The admin transcript payload — sessions welded to the partial marker that
 * qualifies them (C11 operator-participant-visibility D2).
 *
 * `AdminTranscriptSerializer::serialize()` is the SOLE producer. Both
 * consumers — `TranscriptResource` (JSON) and
 * `ParticipantDownloadController::renderTranscriptText()` (.txt) — take this
 * DTO, never a bare array, so omitting the partial marker on a transcript
 * response is a TYPE ERROR, not a caller-discipline lapse.
 *
 * REQ: Partial Transcript Disclosure Marker
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */
final readonly class AdminTranscript
{
    /**
     * @param  array<int, array{session_id: int, competency_code: string, question_index: int, utterances: array<int, array{speaker: string, text: string, ts: string|null}>}>  $sessions
     */
    public function __construct(
        public bool $isPartial,
        public array $sessions,
    ) {}
}
