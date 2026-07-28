<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin TranscriptResource — Transcript scope (C11 D5).
 *
 * Wraps the array produced by `AdminTranscriptSerializer::serialize()` for
 * `GET /api/participants/{id}/transcript` (requires lifecycle >=
 * `in_valutazione`, D2).
 *
 * SCOPE BOUNDARY: Transcript-scope ONLY — MUST NEVER carry Evaluation fields
 * (`score`, `reliability`, `behaviors`). The underlying array is produced
 * exclusively by AdminTranscriptSerializer, which has no access to
 * Evaluation/CompetencyResult/IndicatorScore data, so this is structural, not
 * just a filter.
 */
class TranscriptResource extends JsonResource
{
    /**
     * @param  array<int, array{session_id: int, competency_code: string, question_index: int, utterances: array<int, array{speaker: string, text: string, ts: string|null}>}>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sessions' => $this->resource,
        ];
    }
}
