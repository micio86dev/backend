<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Enums\ApiKeyMode;
use App\Models\AiRequest;
use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLlmUsage;
use App\Models\Organization;
use App\Models\Participant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the `GET /v1/usage` figures (public-api step 8, SPEC.md §3.3/§3.4
 * "Usage specifically") — REUSES `App\Http\Controllers\Api\
 * DashboardController`'s own formulas rather than inventing new ones:
 * `completion_rate` = completed / total over the SAME status-grouped set
 * (`DashboardController::metrics()`), `cost_usd` = scoring
 * (`ai_requests.estimated_cost_usd`) + conversation
 * (`interview_session_llm_usage`, `COALESCE(actual, estimated, 0)`,
 * `DashboardController::costs()`).
 *
 * Two differences from the admin dashboard, both required by binding rules
 * this endpoint alone is subject to:
 *
 * 1. **Mode partitioning (SPEC.md §3.7 "a `beai_test_` key's requests must
 *    never read or write `live` data").** The admin dashboard has no
 *    test/live distinction at all — every admin-facing model it reads
 *    (`Evaluation`, `AiRequest`, `InterviewSessionLlmUsage`) carries no
 *    `mode` column of its own. `participants.mode` is the only column this
 *    schema stamps with the key's mode at write time (step 5's own `mode`
 *    column, migration `2026_09_24_140000_add_public_api_fields_to_participants_table.php`),
 *    so mode-scoping is applied at the `Participant` level and PROPAGATED to
 *    the other three models by filtering on the participant ids that already
 *    matched — `Evaluation`/`InterviewSession` both carry `participant_id`
 *    directly; `AiRequest` reaches it one hop further, through
 *    `evaluation_id`. This is additive, narrowing-only scoping: a
 *    `beai_live_` key's report was already correct before this filter
 *    existed (a fresh install seeds only `live` participants), but a
 *    `beai_test_` key's report was NOT — nothing previously stopped a test
 *    key's usage figures from including the organization's live data. Judged
 *    correct to fix here rather than defer, since SPEC.md §3.7 states the
 *    rule as a hard "must never", not an aspiration.
 * 2. **The public `evaluations` shape is `{completed, pending}` only**
 *    (`openapi.yaml`'s `Usage` schema) — narrower than
 *    `App\Enums\EvaluationStatus`, which also has `processing` (the brief
 *    window while `ScoreEvaluationJob` is actually running). Judgement call,
 *    disclosed rather than silently resolved: a `processing` evaluation has
 *    not produced a completion verdict yet, so it is folded into `pending`
 *    — the same bucket an evaluation that finished below the 90% validity
 *    gate lands in — rather than dropped from the total (which would
 *    silently under-count `evaluations` against `interviews.under_evaluation`
 *    while a job is in flight).
 */
final class UsageAggregator
{
    /**
     * @return array{from: string, to: string, livemode: bool, interviews: array{pending: int, in_progress: int, under_evaluation: int, completed: int, error: int}, evaluations: array{completed: int, pending: int}, completion_rate: float, llm_tokens: array{input: int, output: int}, cost_usd: float, currency: string}
     */
    public static function build(Organization $organization, ApiKeyMode $mode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $interviews = self::interviewCounts($organization, $mode, $from, $to);
        $totalInterviews = array_sum($interviews);
        $completionRate = $totalInterviews > 0
            ? round($interviews['completed'] / $totalInterviews, 4)
            : 0.0;

        $evaluations = self::evaluationCounts($organization, $mode, $from, $to);

        $llmTokens = [
            'input' => (int) AiRequest::query()
                ->whereIn('evaluation_id', self::evaluationIdsQuery($organization, $mode))
                ->whereBetween('created_at', [$from, $to])
                ->sum('input_tokens'),
            'output' => (int) AiRequest::query()
                ->whereIn('evaluation_id', self::evaluationIdsQuery($organization, $mode))
                ->whereBetween('created_at', [$from, $to])
                ->sum('output_tokens'),
        ];

        $scoringCost = (float) AiRequest::query()
            ->whereIn('evaluation_id', self::evaluationIdsQuery($organization, $mode))
            ->whereBetween('created_at', [$from, $to])
            ->sum('estimated_cost_usd');

        // COALESCE, not two sums added — mirrors DashboardController::costs()
        // exactly (see class doc): a settled row carries both an estimate
        // and an actual, and summing both columns would double-count it.
        $conversationCost = (float) InterviewSessionLlmUsage::query()
            ->whereIn('interview_session_id', self::sessionIdsQuery($organization, $mode))
            ->whereBetween('created_at', [$from, $to])
            ->sum(DB::raw('coalesce(actual_cost_usd, estimated_cost_usd, 0)'));

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'livemode' => $mode === ApiKeyMode::Live,
            'interviews' => $interviews,
            'evaluations' => $evaluations,
            'completion_rate' => $completionRate,
            'llm_tokens' => $llmTokens,
            'cost_usd' => round($scoringCost + $conversationCost, 6),
            'currency' => 'USD',
        ];
    }

    /**
     * @return array{pending: int, in_progress: int, under_evaluation: int, completed: int, error: int}
     */
    private static function interviewCounts(Organization $organization, ApiKeyMode $mode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byStatus = Participant::query()
            ->where('organization_id', $organization->id)
            ->where('mode', $mode)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(self::toInt(...));

        return [
            'pending' => (int) ($byStatus->get('in_attesa') ?? 0),
            'in_progress' => (int) ($byStatus->get('in_corso') ?? 0),
            'under_evaluation' => (int) ($byStatus->get('in_valutazione') ?? 0),
            'completed' => (int) ($byStatus->get('completato') ?? 0),
            'error' => (int) ($byStatus->get('errore') ?? 0),
        ];
    }

    /**
     * @return array{completed: int, pending: int}
     */
    private static function evaluationCounts(Organization $organization, ApiKeyMode $mode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byStatus = Evaluation::query()
            ->whereIn('participant_id', self::participantIdsQuery($organization, $mode))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(self::toInt(...));

        return [
            'completed' => (int) ($byStatus->get('completed') ?? 0),
            // 'processing' folded into 'pending' — see class doc, point 2.
            'pending' => (int) ($byStatus->get('pending') ?? 0) + (int) ($byStatus->get('processing') ?? 0),
        ];
    }

    /**
     * A SUBQUERY, never a PHP-materialized id list (gga finding 5): the old
     * `Participant::query()->...->pluck('id')` fed straight into
     * `whereIn()` loads every matching participant id into a PHP array and
     * re-encodes it as one bind parameter per id — past Postgres's
     * ~65,535 bind-parameter limit for a large organization, and wasted
     * memory regardless. `whereIn()` accepts a query builder directly and
     * compiles it as `... IN (SELECT ...)`, so the id set never leaves the
     * database.
     *
     * @return Builder<Participant>
     */
    private static function participantIdsQuery(Organization $organization, ApiKeyMode $mode): Builder
    {
        return Participant::query()
            ->where('organization_id', $organization->id)
            ->where('mode', $mode)
            ->select('id');
    }

    /**
     * Same subquery discipline as `participantIdsQuery()` — every
     * evaluation belonging to one of this organization/mode's own
     * participants, never a materialized id list.
     *
     * @return Builder<Evaluation>
     */
    private static function evaluationIdsQuery(Organization $organization, ApiKeyMode $mode): Builder
    {
        return Evaluation::query()
            ->whereIn('participant_id', self::participantIdsQuery($organization, $mode))
            ->select('id');
    }

    /**
     * Same subquery discipline as `participantIdsQuery()` — every interview
     * session belonging to one of this organization/mode's own
     * participants, never a materialized id list.
     *
     * @return Builder<InterviewSession>
     */
    private static function sessionIdsQuery(Organization $organization, ApiKeyMode $mode): Builder
    {
        return InterviewSession::query()
            ->whereIn('participant_id', self::participantIdsQuery($organization, $mode))
            ->select('id');
    }

    /**
     * Narrows a raw `selectRaw('count(*) as aggregate')` value (the
     * database driver's own numeric-string/int return type — never
     * statically known to PHPStan through a `pluck()` on a raw alias) to a
     * genuine `int` without an unchecked cast on `mixed` — `is_numeric()` is
     * the actual runtime guard, not a cast silencer: an aggregate this
     * query itself produced is always numeric, but a defensive `0` fallback
     * costs nothing and keeps this helper honest about its input type.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
