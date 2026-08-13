<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EvaluationIndexResource (backoffice-missing-pages D6).
 *
 * Serializes ONE row of `EvaluationIndexQuery::build()`'s result — NOT an
 * `Evaluation` model relation-graph, a flat joined row (participant ref,
 * project, assessment type, role code, evaluated_at, status, reliability).
 *
 * `reliability` is attached by the controller BEFORE this resource runs
 * (one grouped `competency_results` query for the whole page, D6) — this
 * resource never queries per row. There is deliberately NO overall
 * candidate score field: none exists in the schema, and deriving one here
 * would bake an unratified business rule into a list view (D6).
 */
class EvaluationIndexResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'participant_id' => $row->participant_id,
            'candidate_ref' => $row->candidate_ref,
            'display_name' => $row->display_name,
            'project_id' => $row->project_id,
            'project_name' => $row->project_name,
            'assessment_type' => $row->assessment_type,
            'role_code' => $row->role_code,
            'evaluated_at' => $row->evaluated_at,
            // 'completed' | 'pending' — the ≥90% valid-competencies gate.
            'status' => $row->evaluation_status,
            // Integer percentage via ReliabilityRenderer, attached by the
            // controller — null only when the page's grouped aggregate
            // query found no competency_results row for this evaluation.
            'reliability' => $row->reliability_percent ?? null,
        ];
    }
}
