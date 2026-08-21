<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Services\Admin\AdminTranscript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin TranscriptResource — Transcript scope (C11 D5).
 *
 * Wraps the `AdminTranscript` DTO produced by
 * `AdminTranscriptSerializer::serialize()` for
 * `GET /api/participants/{id}/transcript` (requires lifecycle >= `in_corso`,
 * OR `errore`, per operator-participant-visibility D1).
 *
 * The constructor accepts ONLY the DTO — never a bare array — so it is a
 * type error, not a caller-discipline lapse, to serve a transcript response
 * without the `is_partial` marker welded to it (D2).
 *
 * SCOPE BOUNDARY: Transcript-scope ONLY — MUST NEVER carry Evaluation fields
 * (`score`, `reliability`, `behaviors`). The underlying DTO is produced
 * exclusively by AdminTranscriptSerializer, which has no access to
 * Evaluation/CompetencyResult/IndicatorScore data, so this is structural, not
 * just a filter.
 *
 * `@scramble-return` below fixes a pre-existing wrong committed snapshot
 * (openapi.json previously declared `sessions: string` — Scramble could not
 * infer the passthrough shape from a bare array `toArray()`).
 */
class TranscriptResource extends JsonResource
{
    public function __construct(AdminTranscript $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{is_partial: bool, sessions: array<int, array{session_id: int, competency_code: string, question_index: int, utterances: array<int, array{speaker: string, text: string, ts: string|null}>}>}
     *
     * @scramble-return array{is_partial: bool, sessions: array<int, array{session_id: int, competency_code: string, question_index: int, utterances: array<int, array{speaker: string, text: string, ts: string|null}>}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'is_partial' => $this->resource->isPartial,
            'sessions' => $this->resource->sessions,
        ];
    }
}
