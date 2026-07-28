<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Participant;
use App\Services\Scoring\ReliabilityRenderer;

/**
 * Builds the admin BARS evaluation report for a Participant (C11 D6).
 *
 * Reads Evaluation/CompetencyResult/IndicatorScore under the ambient
 * TenantContext scope (all three extend TenantModel) — never
 * withoutGlobalScopes(), which is reserved for EvaluationPayloadAssembler's
 * queued-job context (arch-tested, task 5.3).
 *
 * `CompetencyResult.score` is the SERVER-COMPUTED mean, already stored at
 * scoring time (C9 MeanCalculator, CC2: null when every indicator is
 * unassessable). This serializer reads it verbatim — it does NOT recompute
 * the mean, so there is exactly one place that ever computes a competency
 * mean.
 *
 * `reliability` is rendered as a percent string via ReliabilityRenderer (a
 * pure collaborator, reused — not reimplemented) and emitted VERBATIM. No
 * High/Medium/Low band exists or is applied: the threshold formula is open
 * product decision #1 and MUST NOT be invented here.
 *
 * Indicator scores are the discrete set {1,3,5}. `-1` is a sentinel meaning
 * "unassessable" — it is NEVER emitted as the literal -1; it renders as
 * `null` instead, mirroring the codebase's existing "null means no value"
 * convention already used for the competency-level score (CompetencyResult.php:39,67).
 *
 * Ordering: competencies are ordered by `project_competencies.position`, via
 * `Project::competencies()` (Project.php:183-188, already `orderByPivot`) — a
 * pure collaborator, not reimplemented here.
 *
 * REQ: Evaluation Serializer Is Scoped, Not Copied From the Webhook Assembler
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */
final class AdminEvaluationSerializer
{
    public function __construct(
        private readonly ReliabilityRenderer $reliabilityRenderer = new ReliabilityRenderer,
    ) {}

    /**
     * @return array<string, array{
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>}>
     * }>
     */
    public function serialize(Participant $participant): array
    {
        $evaluation = Evaluation::where('participant_id', $participant->id)
            ->with('competencyResults.indicatorScores')
            ->firstOrFail();

        $orderedCodes = $participant->project
            ? $participant->project->competencies()->pluck('code')->all()
            : [];

        /** @var array<string, CompetencyResult> $resultsByCode */
        $resultsByCode = [];
        foreach ($evaluation->competencyResults as $result) {
            $resultsByCode[$result->competency_code] = $result;
        }

        $orderedResults = [];
        foreach ($orderedCodes as $code) {
            if (array_key_exists($code, $resultsByCode)) {
                $orderedResults[$code] = $resultsByCode[$code];
                unset($resultsByCode[$code]);
            }
        }

        // Any CompetencyResult not covered by the project's ordered competency
        // list (e.g. a stale/removed competency) is appended after, in DB order,
        // rather than silently dropped.
        foreach ($resultsByCode as $code => $result) {
            $orderedResults[$code] = $result;
        }

        $output = [];
        foreach ($orderedResults as $code => $result) {
            $output[$code] = $this->serializeCompetencyResult($result);
        }

        return $output;
    }

    /**
     * @return array{
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>}>
     * }
     */
    private function serializeCompetencyResult(CompetencyResult $result): array
    {
        return [
            'score' => $result->score,
            'reliability' => $this->reliabilityRenderer->render($result->reliability).'%',
            'behaviors' => $result->indicatorScores
                ->map(fn (IndicatorScore $indicator): array => [
                    'indicator' => $indicator->indicator_text,
                    // -1 is the unassessable sentinel — never emitted as a literal score.
                    'score' => $indicator->score === -1 ? null : $indicator->score,
                    'explanation' => $indicator->explanation,
                    'excerpts' => $indicator->excerpts,
                ])
                ->values()
                ->all(),
        ];
    }
}
