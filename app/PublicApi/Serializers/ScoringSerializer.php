<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Participant;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Support\PublicApi\PublicId;
use RuntimeException;

/**
 * Public-safe `Scoring` shape for the BEAI Public API (`/v1`) — the binding
 * BARS output (CLAUDE.md, SPEC.md §3.3 "Scoring — response shape"),
 * `public-api/openapi.yaml`'s `Scoring`/`CompetencyScore`/`Behavior`
 * schemas, exercised by `GET /v1/interviews/{id}/scoring`. Read gate
 * (status `completed` only, else `409 scoring_not_ready`) is the CALLER's
 * responsibility, never this class's — see `TranscriptSerializer`'s own
 * docblock for the identical division.
 *
 * Reuses `App\Services\Admin\AdminEvaluationSerializer::
 * orderedCompetencyResults()` for the project-order resolution (extracted
 * there specifically for this reuse — see that method's own docblock) so
 * the public and admin surfaces can never disagree about competency order.
 * Everything else here is a DELIBERATELY different rendering, never a
 * duplicate of the admin shape:
 *
 * - `reliability` is `CompetencyResult.reliability` VERBATIM — a `[0, 1]`
 *   float already (ruling 1: "No High/Medium/Low bands — render the
 *   percentage verbatim" is about the ADMIN read; the binding contract here
 *   declares `reliability: number, minimum: 0, maximum: 1`). Admin's
 *   `ReliabilityRenderer` percent-string rendering (`"67%"`) is a
 *   BACKOFFICE-only presentation choice this class never applies.
 * - `behaviors[].score` keeps the LITERAL `-1` sentinel — a DELIBERATE
 *   contract choice, not an oversight or a drift from the admin surface:
 *   the vendored `public-api/openapi.yaml`'s `IndicatorScore` schema
 *   declares `enum: [1, 2, 3, 4, 5, -1]` explicitly, and SPEC.md §3.3
 *   states the domain meaning directly ("Indicator score ∈ {1,2,3,4,5,-1}
 *   ... `-1` is unassessable and excluded from the competency mean").
 *   `AdminEvaluationSerializer::serializeCompetencyResult()` renders the
 *   SAME fact as `null` instead — a BACKOFFICE-only UI convenience for a
 *   human reader, per that class's own docblock ("mirroring the
 *   codebase's existing 'null means no value' convention") — and that
 *   choice stays exactly as it is; this class never adopts it. Reported
 *   as a G-item (gga review, step 6 follow-up finding 7) rather than
 *   silently assumed obvious, since a future reader comparing the two
 *   renderings side by side could otherwise read this as an inconsistency
 *   to "fix" into agreement.
 * - No `audit`/`meta.audit` sibling — SPEC.md §3.4's exclusion list (the
 *   post-hoc Jev audit verdict is backoffice-only observability, never part
 *   of the binding BARS contract).
 */
final class ScoringSerializer
{
    public function __construct(
        private readonly AdminEvaluationSerializer $adminSerializer = new AdminEvaluationSerializer,
    ) {}

    /**
     * @return array{interview_id: string, status: string, competencies: array<string, array{score: float|null, reliability: float, behaviors: list<array{indicator: string, score: int, explanation: string, excerpts: list<string>, unassessable_reason: string|null}>, unscorable_reason: string|null}>, framework_version: string, model_version: string, prompt_version: string, evaluated_at: string}
     */
    public function toArray(Participant $participant): array
    {
        $evaluation = Evaluation::where('participant_id', $participant->id)
            ->with(['competencyResults.indicatorScores', 'frameworkVersion'])
            ->firstOrFail();

        $frameworkVersion = $evaluation->frameworkVersion;

        // Same "announce, never paper over" discipline as
        // `AdminEvaluationSerializer::meta()` — an evaluation's
        // `framework_version_id` is a NOT NULL FK with `restrictOnDelete`,
        // so a null relation here means the ambient tenant scope filtered
        // it out (a tenancy violation), never a genuinely missing row.
        if ($frameworkVersion === null) {
            throw new RuntimeException(sprintf(
                'ScoringSerializer: evaluation %d references framework_version_id %d, '
                .'but the FrameworkVersion did not resolve under the ambient tenant scope. '
                .'Refusing to serialize scoring provenance without it.',
                $evaluation->id,
                $evaluation->framework_version_id,
            ));
        }

        $orderedResults = $this->adminSerializer->orderedCompetencyResults($evaluation, $participant);

        $competencies = [];
        foreach ($orderedResults as $code => $result) {
            $competencies[$code] = self::serializeCompetencyResult($result);
        }

        return [
            'interview_id' => PublicId::encode($participant),
            'status' => $evaluation->status->value,
            'competencies' => $competencies,
            'framework_version' => $frameworkVersion->version,
            'model_version' => $evaluation->model_version,
            'prompt_version' => $evaluation->prompt_version,
            // NOT NULL by the time status is `completed` (the only status
            // this endpoint's gate ever reads — see this class's own
            // docblock): `ScoreEvaluationJob::resolveEvaluationTerminalState()`
            // sets `evaluated_at` in the SAME write as the terminal status.
            'evaluated_at' => (string) $evaluation->evaluated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{score: float|null, reliability: float, behaviors: list<array{indicator: string, score: int, explanation: string, excerpts: list<string>, unassessable_reason: string|null}>, unscorable_reason: string|null}
     */
    private static function serializeCompetencyResult(CompetencyResult $result): array
    {
        return [
            'score' => $result->score,
            'reliability' => $result->reliability,
            'behaviors' => array_values($result->indicatorScores
                ->map(fn (IndicatorScore $indicator): array => [
                    // The FROZEN, project-language text scored against —
                    // never the catalogue's current-locale name (that
                    // localisation choice is `AdminEvaluationSerializer`'s
                    // own reader-facing convenience; this is evidence of
                    // what was actually asked and scored).
                    'indicator' => $indicator->indicator_text,
                    // LITERAL, never rendered null — see this class's own
                    // docblock.
                    'score' => $indicator->score,
                    'explanation' => $indicator->explanation,
                    // array_values() (step 6 review): `IndicatorScore::
                    // $excerpts` is declared `array<int, string>`, not
                    // `list<string>` — a JSON-decoded array cast has no
                    // guaranteed-sequential keys as far as PHPStan can
                    // prove, even though this column always round-trips a
                    // plain list. Re-indexed here rather than widening the
                    // contract's own `list<string>` return type.
                    'excerpts' => array_values($indicator->excerpts),
                    'unassessable_reason' => $indicator->unassessable_reason,
                ])
                ->values()
                ->all()),
            'unscorable_reason' => $result->unscorable_reason,
        ];
    }
}
