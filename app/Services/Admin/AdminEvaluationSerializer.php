<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\BarsIndicator;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAudit;
use App\Models\Participant;
use App\Models\Role;
use App\Services\Scoring\ReliabilityRenderer;
use App\Support\Admin\AuditVerdictReader;
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
 * `behaviors[].audit` and `meta.audit` (scoring-audit-jev, design D9) add the
 * post-hoc TypeSafe/Jev audit verdict as a SIBLING of every existing key —
 * additive only, nothing above is removed, renamed, or retyped. Resolved
 * exclusively through `AuditVerdictReader` (design D10), the only class in
 * `app/` permitted to query either audit table directly — this class never
 * queries `IndicatorScoreAudit` itself, which is exactly what
 * `AuditAppendOnlyArchTest` arch-tests against. `auditVerdicts()` resolves
 * the LATEST run for the whole report, once, mirroring
 * `indicatorCatalogue()`'s own "one query for the whole report" doctrine —
 * never "latest verdict per indicator" (see `auditVerdicts()`'s own
 * docblock for why those differ).
 *
 * REQ: Evaluation Serializer Is Scoped, Not Copied From the Webhook Assembler
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 * REQ: Evaluation Read Surface Exposes Per-Indicator Audit Status
 *      (openspec/changes/scoring-audit-jev/specs/admin-read-api/spec.md)
 */
final class AdminEvaluationSerializer
{
    public function __construct(
        private readonly ReliabilityRenderer $reliabilityRenderer = new ReliabilityRenderer,
        private readonly AuditVerdictReader $verdictReader = new AuditVerdictReader,
    ) {}

    /**
     * @return array<string, array{
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, unassessable_reason: string|null, audit: array{status: string, support_probability: float|null, outcome_reason: string|null}}>,
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
        $verdicts = $this->auditVerdicts($evaluation->id);

        foreach ($orderedResults as $code => $result) {
            $output[$code] = $this->serializeCompetencyResult($result, $catalogue, $verdicts);
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
     * Takes the resolved `Participant` model, not a bare id (gga review
     * finding): `Participant` does NOT extend TenantModel and carries no
     * global scope, so a bare `Participant::find($id)` here would happily
     * return another organization's row. The caller (`SessionEvidenceReader`)
     * already holds an org-verified `InterviewSession` and resolves its
     * `participant` relation from it — the SAME trust chain `AdminEvaluation
     * Serializer::serialize()`/`meta()` rely on for the `Participant` they
     * are handed, rather than a second, independently-scoped lookup.
     *
     * @return array{
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, unassessable_reason: string|null, audit: array{status: string, support_probability: float|null, outcome_reason: string|null}}>,
     *     unscorable_reason: string|null
     * }|null
     */
    public function serializeCompetency(Participant $participant, string $competencyCode): ?array
    {
        // `indicatorScores` orders itself (`CompetencyResult::indicatorScores()`
        // — `behaviors` is a positional list, and the relation's own default
        // order is what keeps this method and `serialize()` from disagreeing.
        $result = CompetencyResult::query()
            ->whereHas('evaluation', fn ($query) => $query->where('participant_id', $participant->id))
            ->where('competency_code', $competencyCode)
            ->with('indicatorScores')
            ->first();

        if ($result === null) {
            return null;
        }

        // Same localized-indicator-name catalogue `serialize()` uses (gga
        // review finding): this method used to render indicator names in
        // the frozen, project-language `indicator_text` unconditionally,
        // while `serialize()` resolved the reader's locale from the
        // catalogue — the exact defect this class's own docblock says the
        // two surfaces "must never disagree" about.
        //
        // Same `auditVerdicts()` resolution `serialize()` uses, keyed off
        // $result->evaluation_id — already a loaded column on the fetched
        // CompetencyResult, so this costs no extra lookup beyond
        // auditVerdicts()'s own two queries. This is the AD-7/D9 invariant:
        // both surfaces call the identical private helper, so they can never
        // emit a different `audit` object for the same indicator.
        return $this->serializeCompetencyResult(
            $result,
            $this->indicatorCatalogue($participant),
            $this->auditVerdicts($result->evaluation_id),
        );
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
     * Audit-run provenance for the `meta.audit` response sibling
     * (scoring-audit-jev design D9) — the LATEST run's own counters and
     * versions, or `null` when the evaluation has never been audited.
     *
     * Unlike `meta()`, this is non-throwing: "never audited" is an ordinary
     * state at this read surface, not a failure — the same distinction
     * `serializeCompetency()`'s own docblock draws for an unscored
     * competency.
     *
     * @return array{
     *     run_id: int,
     *     status: string,
     *     judge_model_version: string,
     *     audit_prompt_version: string,
     *     created_at: string,
     *     indicators_total: int,
     *     indicators_judged: int,
     *     indicators_skipped: int,
     *     indicators_unavailable: int,
     *     indicators_malformed: int
     * }|null
     */
    public function auditMeta(Participant $participant): ?array
    {
        $evaluation = Evaluation::where('participant_id', $participant->id)->first();

        if ($evaluation === null) {
            return null;
        }

        $run = $this->verdictReader->latestRunFor($evaluation->id);

        if ($run === null) {
            return null;
        }

        return [
            'run_id' => $run->id,
            'status' => $run->status->value,
            'judge_model_version' => $run->judge_model_version,
            'audit_prompt_version' => $run->audit_prompt_version,
            'created_at' => $run->created_at->toIso8601String(),
            'indicators_total' => $run->indicators_total,
            'indicators_judged' => $run->indicators_judged,
            'indicators_skipped' => $run->indicators_skipped,
            'indicators_unavailable' => $run->indicators_unavailable,
            'indicators_malformed' => $run->indicators_malformed,
        ];
    }

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
        $project = $participant->project;

        if ($project === null) {
            return [];
        }

        $roleCode = $project->role_code ?? $participant->role_code;

        if ($roleCode === null) {
            return [];
        }

        // The project's OWN pinned revision (framework-catalogue-authoring
        // PR3b, H1) — an unscoped `where('code', ...)` would resolve
        // whichever of a baseline/draft pair sharing this code Postgres
        // returns first, and this report can be read long after a later
        // draft has been opened for unrelated authoring work.
        //
        // NEVER `CatalogueRevisionResolver::forProject()`/`tryForProject()`
        // (gga review finding): both fall back to the LATEST PUBLISHED
        // revision when there is no project to pin against, which is the
        // wrong default for a report — it must render indicator NAMES
        // against the revision the project was ACTUALLY pinned to, never
        // whatever happens to be newest when the report is later viewed
        // (CLAUDE.md ruling 3). An unpinned project degrades to the SAME
        // empty-map fallback as "no role" above, matching this method's own
        // documented best-effort contract — the stored `indicator_text` is
        // exactly right for evidence, unlike `meta()`'s scoring provenance,
        // which throws because it has no fallback text to degrade to.
        $version = $project->frameworkVersion;

        if ($version === null || $version->revision_id === null) {
            return [];
        }

        $role = Role::where('code', $roleCode)->where('revision_id', $version->revision_id)->first();

        if ($role === null) {
            return [];
        }

        $map = [];

        BarsIndicator::where('role_id', $role->id)
            ->where('revision_id', $version->revision_id)
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
     * Every indicator's LATEST-run audit verdict for one evaluation, keyed by
     * `indicator_score_id` — resolved through `AuditVerdictReader` (design
     * D10) ONCE for the whole report, mirroring `indicatorCatalogue()`'s own
     * "one query for the whole report" doctrine.
     *
     * Takes `int $evaluationId` rather than `Participant` (a deliberate
     * refinement of design D9's literal signature, same class of refinement
     * P4's controller lock try/catch already needed over D12's literal code
     * sketch): both callers already hold the evaluation id for free —
     * `serialize()` from the `Evaluation` it already fetched, `serializeCompetency()`
     * from `$result->evaluation_id`, an already-loaded column — so resolving it
     * from a bare `Participant` here would cost a THIRD query this method's own
     * "exactly 2 queries" contract (`AuditVerdictsQueryCountTest`) does not pay.
     *
     * Exactly 2 queries when the evaluation HAS been audited
     * (`latestRunFor()` + `verdictsForRun()`), exactly 1 when it has not
     * (`latestRunFor()` returns null, `verdictsForRun()` is never called).
     *
     * @return array<int, IndicatorScoreAudit>
     */
    private function auditVerdicts(int $evaluationId): array
    {
        $run = $this->verdictReader->latestRunFor($evaluationId);

        if ($run === null) {
            return [];
        }

        return $this->verdictReader->verdictsForRun($run->id);
    }

    /**
     * @param  array<string, string>  $catalogue  indicator names in the reader's
     *                                            locale, keyed `CODE:position`
     * @param  array<int, IndicatorScoreAudit>  $verdicts  keyed by indicator_score_id
     * @return array{
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, unassessable_reason: string|null, audit: array{status: string, support_probability: float|null, outcome_reason: string|null}}>,
     *     unscorable_reason: string|null
     * }
     */
    private function serializeCompetencyResult(CompetencyResult $result, array $catalogue = [], array $verdicts = []): array
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
                    // ADDITIVE ONLY (scoring-audit-jev spec — "MUST NOT
                    // remove, rename, or change the type of any existing
                    // field"). NEVER a missing key: an indicator with no
                    // verdict row renders the synthetic `never_audited`
                    // status rather than being omitted.
                    'audit' => $this->serializeAudit($verdicts[$indicator->id] ?? null),
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

    /**
     * One indicator's `audit` object — `never_audited` (a WIRE-ONLY status,
     * never a value the `indicator_score_audits.status` CHECK admits) when no
     * verdict row exists for it in the latest run, otherwise the verdict's
     * own status/probability/reason verbatim (scoring-audit-jev spec, design
     * D9).
     *
     * `outcome_reason`, not `reason` — design D9's own code sketch says
     * `reason`, but the RATIFIED spec.md scenario
     * ("`audit: { status: "judged", support_probability: 0.82, outcome_reason: null }`")
     * and the column's own name (design C-E) both say `outcome_reason`. The
     * spec is the WHAT and wins over a stale code sketch in the HOW document
     * — the same class of correction this codebase already makes explicit
     * for a drifted path (C-A) or a drifted column name (C-E) elsewhere in
     * this very change.
     *
     * `support_probability` is cast `decimal:4` on the model (a STRING, to
     * avoid float-precision loss on write) — cast to `float` here because the
     * WIRE contract is `float|null` (spec.md, `EvaluationKeySetTest`).
     *
     * @return array{status: string, support_probability: float|null, outcome_reason: string|null}
     */
    private function serializeAudit(?IndicatorScoreAudit $audit): array
    {
        if ($audit === null) {
            return ['status' => 'never_audited', 'support_probability' => null, 'outcome_reason' => null];
        }

        return [
            'status' => $audit->status->value,
            'support_probability' => $audit->support_probability === null
                ? null
                : (float) $audit->support_probability,
            'outcome_reason' => $audit->outcome_reason?->value,
        ];
    }
}
