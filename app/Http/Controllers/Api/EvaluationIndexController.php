<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EvaluationIndexRequest;
use App\Http\Resources\Admin\EvaluationIndexResource;
use App\Models\Evaluation;
use App\Services\Scoring\ReliabilityRenderer;
use App\Support\Admin\EvaluationIndexQuery;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EvaluationIndexController (backoffice-missing-pages D6/D7 — admin-read-api
 * delta).
 *
 * GET /api/evaluations          — index (paginated, lifecycle-gated)
 * GET /api/evaluations/summary  — mean competency score per code, over the
 *                                  SAME filtered population as the index
 *
 * Both actions build their row set exclusively through
 * `EvaluationIndexQuery::build()` — the ONE place the lifecycle gate is
 * stated (D6) — so the two endpoints can never describe two different
 * populations for the same filters (D7).
 */
class EvaluationIndexController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly EvaluationIndexQuery $query,
        private readonly TenantResolver $resolver,
        private readonly ReliabilityRenderer $reliabilityRenderer = new ReliabilityRenderer,
    ) {}

    /**
     * GET /api/evaluations
     *
     * Two queries total, never per row (D6): the paginated page (via
     * `simplePaginate()` — no separate COUNT query, matching design D6's
     * "two queries per request, never per row"), plus ONE grouped query
     * over `competency_results` for the page's ids to attach each row's
     * mean reliability.
     */
    public function index(EvaluationIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Evaluation::class);

        // simplePaginate(), not paginate(): the latter issues a second
        // COUNT(*) query, which would make "the page" two queries by
        // itself before the grouped aggregate is even run.
        Paginator::currentPageResolver(fn () => (int) $request->integer('page', 1));
        $page = $this->query->build($request->toFilters())->simplePaginate(self::PER_PAGE);

        $ids = $page->getCollection()->pluck('id');
        $reliabilityByEvaluationId = $this->reliabilityByEvaluationId($ids);

        $page->getCollection()->transform(function ($row) use ($reliabilityByEvaluationId) {
            // setAttribute(), not direct property assignment — this is a
            // dynamically-selected column on an Evaluation instance, not a
            // typed class property (same discipline as TenantScoped.php's
            // organization_id stamp).
            $row->setAttribute(
                'reliability_percent',
                $reliabilityByEvaluationId->has($row->id)
                    ? $this->reliabilityRenderer->render((float) $reliabilityByEvaluationId->get($row->id))
                    : null,
            );

            return $row;
        });

        return EvaluationIndexResource::collection($page);
    }

    /**
     * GET /api/evaluations/summary
     *
     * Aggregates over the identical filter set, sourced from the SAME
     * `EvaluationIndexQuery::build()` call as the index — so the summary
     * can never describe a different population than the table above it.
     */
    public function summary(EvaluationIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Evaluation::class);

        $filters = $request->toFilters();
        $orgId = $this->resolver->getOrgId();

        $ids = $this->query->build($filters)->pluck('evaluations.id');

        $competencies = DB::table('competency_results')
            ->where('organization_id', $orgId)
            ->whereIn('evaluation_id', $ids)
            ->selectRaw('competency_code, round(avg(score)::numeric, 2) as mean_score, count(score) as scored_count, count(*) as result_count')
            ->groupBy('competency_code')
            ->orderBy('competency_code')
            ->get()
            ->map(fn ($row): array => [
                'competency_code' => $row->competency_code,
                'mean_score' => $row->mean_score !== null ? (float) $row->mean_score : null,
                'scored_count' => (int) $row->scored_count,
                'result_count' => (int) $row->result_count,
            ])
            ->values();

        $byStatus = $this->query->build($filters)
            // reorder(): build()'s fixed `evaluated_at desc, id desc` sort
            // (never a client-specified column, C11 D5) is meaningless once
            // this query is collapsed to a GROUP BY, and Postgres rejects an
            // ORDER BY referencing a column absent from the GROUP BY/aggregate.
            ->reorder()
            ->select([]) // clear the base select — only the aggregate below matters
            ->selectRaw('evaluations.status as status, count(*) as aggregate')
            ->groupBy('evaluations.status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'data' => [
                'by_status' => $byStatus,
                'competencies' => $competencies,
            ],
        ]);
    }

    /**
     * One grouped query over `competency_results` for a page's evaluation
     * ids — mean reliability per evaluation, never per-row.
     *
     * @param  Collection<int, int>  $evaluationIds
     * @return Collection<int, float>
     */
    private function reliabilityByEvaluationId($evaluationIds)
    {
        if ($evaluationIds->isEmpty()) {
            return collect();
        }

        $orgId = $this->resolver->getOrgId();

        return DB::table('competency_results')
            ->where('organization_id', $orgId)
            ->whereIn('evaluation_id', $evaluationIds)
            ->selectRaw('evaluation_id, avg(reliability) as avg_reliability')
            ->groupBy('evaluation_id')
            ->pluck('avg_reliability', 'evaluation_id');
    }
}
