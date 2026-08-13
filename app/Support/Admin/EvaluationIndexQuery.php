<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Evaluation;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ONE shared query builder behind both `/api/evaluations` and
 * `/api/evaluations/summary` (backoffice-missing-pages D6/D7).
 *
 * The lifecycle read-gate is a JOIN PREDICATE here, not a procedural check
 * at each call site, so it cannot be forgotten when a third endpoint is
 * added later: a participant who is not `completato` is structurally
 * ABSENT from the result set, not merely nulled-out in a serializer.
 *
 * `Evaluation` extends TenantModel, so its own org scope applies
 * automatically. The join to `participants` re-asserts the org filter
 * explicitly (`participants` is a plain Model, D1's "belt and braces") —
 * exactly the same defense-in-depth AdminCrossTenantIsolationTest already
 * exercises for the sibling C11 surface.
 *
 * Sort is FIXED at `evaluated_at desc, id desc` — no client-specified sort
 * column ever reaches this builder (C11 D5); `EvaluationIndexRequest` has
 * no sort field at all.
 */
final class EvaluationIndexQuery
{
    /**
     * Terminal evaluation statuses only — a participant mid-scoring
     * (`evaluations.status = 'processing'`) is never eligible, matching the
     * ≥90%-valid-competencies completion gate (`completed`/`pending` are
     * both terminal; `processing` never resolves the participant to
     * `completato` in the first place).
     *
     * @var list<string>
     */
    private const TERMINAL_STATUSES = ['completed', 'pending'];

    public function __construct(
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * @return Builder<Evaluation>
     */
    public function build(EvaluationIndexFilters $filters): Builder
    {
        $orgId = $this->resolver->getOrgId();

        $query = Evaluation::query()
            ->join('participants', function ($join) use ($orgId): void {
                $join->on('participants.id', '=', 'evaluations.participant_id')
                    // Belt and braces (D1): participants is a plain Model,
                    // no TenantScoped global scope of its own.
                    ->where('participants.organization_id', '=', $orgId);
            })
            ->join('projects', 'projects.id', '=', 'participants.project_id')
            // THE GATE — a join predicate, stated once. A participant below
            // `completato` is structurally absent from every row this
            // builder can ever produce.
            ->where('participants.status', '=', 'completato')
            ->whereIn('evaluations.status', self::TERMINAL_STATUSES)
            ->select([
                'evaluations.id as id',
                'evaluations.participant_id as participant_id',
                'evaluations.status as evaluation_status',
                'evaluations.evaluated_at as evaluated_at',
                'participants.candidate_ref as candidate_ref',
                'participants.display_name as display_name',
                'participants.project_id as project_id',
                'participants.role_code as role_code',
                'projects.name as project_name',
                'projects.assessment_type as assessment_type',
            ]);

        if ($filters->projectId !== null) {
            $query->where('participants.project_id', '=', $filters->projectId);
        }

        if ($filters->assessmentType !== null) {
            $query->where('projects.assessment_type', '=', $filters->assessmentType);
        }

        if ($filters->roleCode !== null) {
            $query->where('participants.role_code', '=', $filters->roleCode);
        }

        if ($filters->status !== null) {
            $query->where('evaluations.status', '=', $filters->status);
        }

        if ($filters->evaluatedFrom !== null) {
            $query->where('evaluations.evaluated_at', '>=', $filters->evaluatedFrom);
        }

        if ($filters->evaluatedTo !== null) {
            $query->where('evaluations.evaluated_at', '<=', $filters->evaluatedTo);
        }

        return $query
            ->orderByDesc('evaluations.evaluated_at')
            ->orderByDesc('evaluations.id');
    }
}
