<?php

declare(strict_types=1);

/**
 * Z11 (R3-indicator-scores-default-order-aggregate, REQUIRED BEFORE
 * ARCHIVE): `CompetencyResult::indicatorScores()` carries a built-in
 * `orderBy('position')->orderBy('id')` (framework-catalogue-authoring PR3b,
 * H11) — correct and load-bearing for every ORDINARY read (`AdminEvaluationSerializer`'s
 * own "the session view and the full report must never disagree" contract),
 * but Postgres refuses a direct AGGREGATE (`avg()`, `sum()`, a grouped
 * `count()`) run against a query that still carries that `ORDER BY`: the
 * ordering columns are not in the implicit `GROUP BY` an aggregate query
 * produces ("column must appear in the GROUP BY clause or be used in an
 * aggregate function").
 *
 * No current production call site runs an aggregate through this relation
 * (`MeanCalculator` computes the mean in PHP from a plain array, not a DB
 * aggregate) — this is a LATENT trap for a future caller, proven here so it
 * is documented as a real, reproduced failure mode rather than a guess, with
 * the fix pattern (`reorder()`, clearing the relation's own ordering before
 * aggregating) proven to work.
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function z11CompetencyResult(): CompetencyResult
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    return CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id]);
}

test('Z11: a hand-written groupBy() aggregate on indicatorScores() fails on Postgres because of the relation own default orderBy', function (): void {
    $competencyResult = z11CompetencyResult();
    IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id, 'position' => 0, 'score' => 3]);
    IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id, 'position' => 1, 'score' => 5]);

    // `avg()`/`sum()`/`count()` called DIRECTLY on the relation query, and
    // Eloquent's own `withAvg()`/`withCount()` eager-load helpers, do NOT
    // reproduce this failure in the installed Laravel version — both
    // `Builder::setAggregate()` and `QueriesRelationships::withAggregate()`
    // already strip `orders` themselves before running. The failure is
    // specific to a HAND-WRITTEN aggregate + `groupBy()` query — the shape a
    // dashboard/report reducer would write directly — which goes through
    // neither of those helpers and so does NOT get its `ORDER BY position,
    // id` cleared before Postgres sees it grouped only by
    // `competency_result_id`.
    expect(fn () => $competencyResult->indicatorScores()
        ->groupBy('competency_result_id')
        ->selectRaw('avg(score) as avg_score')
        ->get())
        ->toThrow(QueryException::class);
});

test('Z11: reorder() before a groupBy() aggregate avoids the GROUP BY failure and returns the correct mean', function (): void {
    $competencyResult = z11CompetencyResult();
    IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id, 'position' => 0, 'score' => 3]);
    IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id, 'position' => 1, 'score' => 5]);

    $row = $competencyResult->indicatorScores()
        ->reorder()
        ->groupBy('competency_result_id')
        ->selectRaw('avg(score) as avg_score')
        ->first();

    expect((float) $row->avg_score)->toBe(4.0);
});
