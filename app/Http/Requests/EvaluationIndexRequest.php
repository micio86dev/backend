<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Evaluation;
use App\Support\Admin\EvaluationIndexFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * EvaluationIndexRequest (backoffice-missing-pages D6).
 *
 * Validates `GET /api/evaluations` and `GET /api/evaluations/summary` — the
 * identical filter set for both, so a caller cannot describe two different
 * populations to the two endpoints. NO client-specified sort column reaches
 * the query builder (C11 D5) — sort is fixed at `evaluated_at desc, id desc`
 * inside EvaluationIndexQuery and is not a request field at all.
 *
 * The `reliability >= threshold` filter from the original proposal is
 * deliberately absent (D6): there is no evaluation-level reliability
 * column — it is an aggregate over `competency_results` — so filtering on
 * it would require `HAVING avg(...)`, which no index can serve.
 */
class EvaluationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Evaluation::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'integer'],
            'assessment_type' => ['sometimes', 'string', Rule::in(['standard', 'potential'])],
            'role_code' => ['sometimes', 'string', Rule::in(['ICO', 'FLL', 'MLL', 'BUL', 'SRX'])],
            'status' => ['sometimes', 'string', Rule::in(['completed', 'pending'])],
            'evaluated_from' => ['sometimes', 'date'],
            'evaluated_to' => ['sometimes', 'date'],
        ];
    }

    public function toFilters(): EvaluationIndexFilters
    {
        return new EvaluationIndexFilters(
            projectId: $this->has('project_id') ? (int) $this->validated('project_id') : null,
            assessmentType: $this->validated('assessment_type'),
            roleCode: $this->validated('role_code'),
            status: $this->validated('status'),
            evaluatedFrom: $this->validated('evaluated_from'),
            evaluatedTo: $this->validated('evaluated_to'),
        );
    }
}
