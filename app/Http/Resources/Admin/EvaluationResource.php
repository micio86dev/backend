<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin EvaluationResource — Evaluation scope (C11 D5).
 *
 * Wraps the array produced by `AdminEvaluationSerializer::serialize()` for
 * `GET /api/participants/{id}/evaluation` (requires lifecycle ===
 * `completato`, D2). Top-level shape mirrors
 * `esempio-report-valutazione.json` — keyed by competency code, no envelope.
 *
 * SCOPE BOUNDARY: Evaluation-scope ONLY — MUST NEVER carry Transcript fields
 * (`utterances`, `speaker`). The underlying array is produced exclusively by
 * AdminEvaluationSerializer, which has no access to
 * InterviewSession/Utterance data, so this is structural, not just a filter.
 */
class EvaluationResource extends JsonResource
{
    /**
     * @param  array<string, array{score: float|null, reliability: string, behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>}>}>  $resource
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
        return $this->resource;
    }
}
