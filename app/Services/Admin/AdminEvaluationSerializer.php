<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Participant;
use App\Services\Scoring\ReliabilityRenderer;
use RuntimeException;

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
 * Indicator scores are one integer from {1,2,3,4,5} ∪ {-1}. `-1` is a
 * sentinel meaning "unassessable" — it is NEVER emitted as the literal -1;
 * it renders as `null` instead, mirroring the codebase's existing "null
 * means no value" convention already used for the competency-level score
 * (CompetencyResult.php:39,67).
 *
 * `meta()` (D7, bars-full-scale-1-5) returns the Evaluation's already-persisted
 * scoring provenance (`prompt_version`, `model_version`, `framework_version` —
 * the RESOLVED FrameworkVersion.version string, never the raw FK id) for
 * exposure as a `meta.scoring` sibling of `data` at the read surface. Nothing
 * new is computed here; the values already exist on the Evaluation row.
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
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, unassessable_reason: string|null}>,
     *     unscorable_reason: string|null
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
     * Scoring provenance for the `meta.scoring` response sibling (D7).
     *
     * `framework_version` is the resolved `FrameworkVersion.version` string,
     * loaded through the existing FK relation under the ambient tenant
     * scope — never the raw `framework_version_id`, which means nothing to
     * an operator.
     *
     * @return array{prompt_version: string, model_version: string, framework_version: string}
     */
    public function meta(Participant $participant): array
    {
        $evaluation = Evaluation::where('participant_id', $participant->id)
            ->with('frameworkVersion')
            ->firstOrFail();

        $frameworkVersion = $evaluation->frameworkVersion;

        // `evaluations.framework_version_id` is NOT NULL behind a real FK with
        // restrictOnDelete (create_evaluations_table:48-50), so this relation
        // cannot be null because the row is missing — the database forbids it.
        // The one way it resolves to null is the ambient tenant scope filtering
        // the FrameworkVersion out, i.e. serving an evaluation whose framework
        // belongs to another organization. That is a tenancy violation, and it
        // must announce itself rather than fatal on a property access or, worse,
        // be papered over with a placeholder string that an operator would read
        // as real provenance.
        if ($frameworkVersion === null) {
            throw new RuntimeException(sprintf(
                'AdminEvaluationSerializer: evaluation %d references framework_version_id %d, '
                .'but the FrameworkVersion did not resolve under the ambient tenant scope. '
                .'Refusing to serialize scoring provenance without it.',
                $evaluation->id,
                $evaluation->framework_version_id,
            ));
        }

        return [
            'prompt_version' => $evaluation->prompt_version,
            'model_version' => $evaluation->model_version,
            'framework_version' => $frameworkVersion->version,
        ];
    }

    /**
     * @return array{
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, unassessable_reason: string|null}>,
     *     unscorable_reason: string|null
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
                    // Machine-facing value, unlocalized per CLAUDE.md — the
                    // indicator-grain sibling of unscorable_reason (competency
                    // grain) and failure_reason (ai_requests grain). `null`
                    // for a legally-scored indicator (B3, admin-read-api D11).
                    'unassessable_reason' => $indicator->unassessable_reason,
                ])
                ->values()
                ->all(),
            // Machine-facing value, unlocalized per CLAUDE.md — returned literally
            // in every locale. `null` for a scored competency (scoring-failure-
            // containment D11/admin-read-api). Localization of the LABEL happens
            // in the backoffice, never here.
            'unscorable_reason' => $result->unscorable_reason,
        ];
    }
}
