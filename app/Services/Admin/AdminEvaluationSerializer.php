<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\BarsIndicator;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Participant;
use App\Models\Role;
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
        $catalogue = $this->indicatorCatalogue($participant);

        foreach ($orderedResults as $code => $result) {
            $output[$code] = $this->serializeCompetencyResult($result, $catalogue);
        }

        return $output;
    }

    /**
     * ONE competency's result for one participant, in the EXACT shape
     * `serialize()` emits per competency — or `null` when that competency was
     * never scored.
     *
     * The admin session review needs the evidence for the single competency its
     * session probed. Reusing `serializeCompetencyResult()` rather than shaping
     * a second payload is deliberate: the session view and the full report must
     * never disagree about how a score, a reliability percentage, an
     * unassessable sentinel, or an excerpt is rendered.
     *
     * Non-throwing, unlike `serialize()`: an unscored competency is an ordinary
     * state at this read surface, not a failure.
     *
     * Tenancy rides on the ambient global scope (CompetencyResult and Evaluation
     * both extend TenantModel) — never `withoutGlobalScopes()`, which is
     * reserved for the queued-job assembler.
     *
     * @return array{
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, unassessable_reason: string|null}>,
     *     unscorable_reason: string|null
     * }|null
     */
    public function serializeCompetency(int $participantId, string $competencyCode): ?array
    {
        $result = CompetencyResult::query()
            ->whereHas('evaluation', fn ($query) => $query->where('participant_id', $participantId))
            ->where('competency_code', $competencyCode)
            // Explicitly ordered: `behaviors` is a positional list, and DB
            // insertion order is a coincidence, not a guarantee.
            ->with(['indicatorScores' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->first();

        return $result === null ? null : $this->serializeCompetencyResult($result);
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
    /**
     * Indicator names for this participant's role, in the ACTIVE locale, keyed
     * `COMPETENCY_CODE:position`.
     *
     * One query for the whole report rather than one per indicator — a
     * competency has several, and a report has many competencies.
     *
     * Keyed on `(competency_code, position)` because that is the pair an
     * `IndicatorScore` actually carries: it stores the text it was scored
     * against, not a foreign key to the catalogue row, so there is no id to
     * join on. Position is stable within a competency by definition — it is
     * what orders the anchors — and the framework version is pinned at project
     * creation and never retargeted (CLAUDE.md ruling 3), so the pair cannot
     * drift under a live project.
     *
     * Returns an empty map when the participant has no project or no role: the
     * caller then falls back to the stored text, which is exactly right.
     *
     * @return array<string, string>
     */
    private function indicatorCatalogue(Participant $participant): array
    {
        // The FK-backed relation is inferred NON-null by Larastan, so `?->`
        // on it is reported as dead code — the participant's own `role_code`
        // is read as the fallback for a project that has none.
        $roleCode = $participant->project->role_code ?? $participant->role_code;

        if ($roleCode === null) {
            return [];
        }

        $role = Role::where('code', $roleCode)->first();

        if ($role === null) {
            return [];
        }

        $map = [];

        BarsIndicator::where('role_id', $role->id)
            ->with('competency:id,code')
            ->get()
            ->each(function (BarsIndicator $indicator) use (&$map): void {
                $code = $indicator->competency?->code;

                if ($code === null) {
                    return;
                }

                // `getTranslation` with the active locale, falling back
                // through spatie's own chain — an indicator authored in only
                // one language must still render, and CLAUDE.md records that
                // non-English anchors are an OPEN question (ruling 6).
                $map[$code.':'.$indicator->position] = (string) $indicator->getTranslation(
                    'text',
                    app()->getLocale(),
                    true,
                );
            });

        return $map;
    }

    /**
     * @param  array<string, string>  $catalogue  indicator names in the reader's
     *                                            locale, keyed `CODE:position`
     * @return array{
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, unassessable_reason: string|null}>,
     *     unscorable_reason: string|null
     * }
     */
    private function serializeCompetencyResult(CompetencyResult $result, array $catalogue = []): array
    {
        return [
            'score' => $result->score,
            'reliability' => $this->reliabilityRenderer->render($result->reliability).'%',
            'behaviors' => $result->indicatorScores
                ->map(fn (IndicatorScore $indicator): array => [
                    // Rendered in the READER's language, from the catalogue.
                    //
                    // `indicator_text` is frozen at scoring time in the
                    // PROJECT's language, which is right for evidence — an
                    // explanation of what a candidate said is a record of an
                    // assessment conducted in one language, not UI copy. An
                    // indicator NAME is neither: it is catalogue data authored
                    // in both languages, and the operator reading the report is
                    // not the candidate. An Italian operator was reading
                    // English indicator names on every English-language
                    // project.
                    //
                    // Falls back to the stored text, which is the only correct
                    // answer when the pinned framework version no longer
                    // carries that indicator: a report must render the
                    // indicator that was actually scored, not the nearest
                    // surviving one.
                    'indicator' => $catalogue[$result->competency_code.':'.$indicator->position]
                        ?? $indicator->indicator_text,
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
