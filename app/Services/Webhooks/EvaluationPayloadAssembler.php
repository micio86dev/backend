<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookEventType;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Scoring\ReliabilityRenderer;
use Illuminate\Support\Facades\DB;

/**
 * EvaluationPayloadAssembler — builds the frozen `evaluation` webhook payload
 * (C10 — Webhooks Integration, design.md D7).
 *
 * Called once, at delivery-row-creation time (WebhookDeliveryRecorder, PR4) — the
 * returned array is persisted verbatim to `webhook_deliveries.payload` and never
 * regenerated, even if the underlying Evaluation is later re-scored (a re-score emits
 * a NEW event → a new delivery row, design.md "Frozen payload drifts" risk mitigation).
 *
 * All reads use `withoutGlobalScopes()` and resolve org indirectly through the
 * already-known evaluation/participant/project chain — this class is designed to run
 * from a queued job with a null ambient tenant resolver (S3/S7), matching the
 * established `ScoreEvaluationJob` read pattern (`ScoreEvaluationJob.php:117,155,245`).
 *
 * REQ: Payload assembly — evaluation event (C10 D7)
 */
final class EvaluationPayloadAssembler
{
    public function __construct(
        private readonly ReliabilityRenderer $reliabilityRenderer,
    ) {}

    /**
     * Assemble the payload for a terminal (completed|pending) Evaluation —
     * EvaluationCompleted's consumer.
     *
     * @return array<string, mixed>
     */
    public function assembleForEvaluation(int $evaluationId, string $deliveryId): array
    {
        $evaluation = Evaluation::withoutGlobalScopes()->findOrFail($evaluationId);
        $participant = Participant::findOrFail($evaluation->participant_id);
        $project = Project::withoutGlobalScopes()->findOrFail($participant->project_id);

        return $this->envelope($deliveryId, $participant, $project, [
            'status' => $evaluation->status->value,
            'text' => $this->renderText($evaluation->id, $project->id),
            'files' => $this->renderFiles($participant->id, $evaluation->id),
        ]);
    }

    /**
     * Assemble the payload for a catastrophic scoring failure — EvaluationFailed's
     * consumer. Ships `status` from the Evaluation row if one exists (e.g. it reached
     * 'processing' before the job failed), else the terminal participant state
     * (design.md D7). `text` is always empty — there is no scored data to report.
     *
     * @return array<string, mixed>
     */
    public function assembleForFailedParticipant(int $participantId, string $deliveryId): array
    {
        $participant = Participant::findOrFail($participantId);
        $project = Project::withoutGlobalScopes()->findOrFail($participant->project_id);
        $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participantId)->first();

        $status = $evaluation?->status->value ?? $participant->status;

        return $this->envelope($deliveryId, $participant, $project, [
            'status' => $status,
            'text' => [],
            'files' => $this->renderFiles($participant->id, $evaluation?->id),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $deliveryId, Participant $participant, Project $project, array $data): array
    {
        return [
            'version' => (string) config('webhooks.payload.version'),
            'event' => WebhookEventType::Evaluation->value,
            'delivery_id' => $deliveryId,
            'occurred_at' => now()->utc()->toIso8601String(),
            'candidate_ref' => $participant->candidate_ref,
            'project' => [
                'id' => $project->id,
                'slug' => $project->slug,
            ],
            'data' => $data,
        ];
    }

    /**
     * Renders the `text` block: a map keyed by competency code, ordered by
     * `project_competencies.position`. Reproduces
     * docs/app_description/03-ux-reference/esempio-report-valutazione.json exactly —
     * `behaviors[]{indicator,score,explanation,excerpts}`, `reliability`, `score`, plus
     * an ADDITIVE `unscorable_reason` key present only when the competency is
     * unscorable.
     *
     * @return array<string, array<string, mixed>>
     */
    private function renderText(int $evaluationId, int $projectId): array
    {
        $results = CompetencyResult::withoutGlobalScopes()
            ->where('evaluation_id', $evaluationId)
            ->with('indicatorScores')
            ->get()
            ->keyBy('competency_code');

        // Competency table is 'framework_competencies' (Competency.php:36).
        $orderedCodes = DB::table('project_competencies')
            ->join('framework_competencies', 'project_competencies.competency_id', '=', 'framework_competencies.id')
            ->where('project_competencies.project_id', $projectId)
            ->orderBy('project_competencies.position')
            ->pluck('framework_competencies.code');

        $text = [];

        foreach ($orderedCodes as $code) {
            /** @var CompetencyResult|null $result */
            $result = $results->get($code);

            if ($result === null) {
                continue;
            }

            $entry = [
                'behaviors' => $result->indicatorScores
                    ->sortBy('position')
                    ->values()
                    ->map(fn (IndicatorScore $indicator): array => [
                        'indicator' => $indicator->indicator_text,
                        'score' => $indicator->score,
                        'explanation' => $indicator->explanation,
                        'excerpts' => $indicator->excerpts,
                    ])
                    ->all(),
                'reliability' => $this->reliabilityRenderer->render((float) $result->reliability).'%',
                'score' => $result->score,
            ];

            // Additive-only: absent entirely for scored competencies.
            if ($result->unscorable_reason !== null) {
                $entry['unscorable_reason'] = $result->unscorable_reason;
            }

            $text[$code] = $entry;
        }

        return $text;
    }

    /**
     * `files` — transcript + evaluation_raw references, each additively carrying a
     * `url` key (design.md D9 + webhooks-integration/design.md:309,460 — Δ3: C11 adds
     * `url` additively once a read endpoint exists). Both routes are keyed by
     * participant id (ParticipantDownloadController::transcript()/evaluation()), never
     * evaluation id — see the route definitions in routes/api.php. `route()` resolves
     * absolute (default `$absolute = true`); this assembler runs from a queued job with
     * no ambient HTTP request (see class docblock), so the UrlGenerator falls back to
     * `config('app.url')` as the root rather than any per-request host. Per-question
     * `audio` is an explicit non-goal (open product decision #2, GDPR retention). This
     * map is documented to receivers as OPEN and EXTENSIBLE — adding `audio` later is
     * additive, never a breaking payload-shape change.
     *
     * @return array<string, array<string, string>>
     */
    private function renderFiles(int $participantId, ?int $evaluationId): array
    {
        $files = [
            'transcript' => [
                'type' => 'transcript',
                'ref' => "participant:{$participantId}",
                'url' => route('admin.participants.transcript.download', ['id' => $participantId]),
            ],
        ];

        if ($evaluationId !== null) {
            $files['evaluation_raw'] = [
                'type' => 'evaluation',
                'ref' => "evaluation:{$evaluationId}",
                'url' => route('admin.participants.evaluation.download', ['id' => $participantId]),
            ];
        }

        return $files;
    }
}
