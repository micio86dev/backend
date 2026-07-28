<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookEventType;
use App\Models\Participant;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ProgressPayloadAssembler — builds the frozen `progress` webhook payload
 * (C10 — Webhooks Integration, design.md D7).
 *
 * `data.competencies` is the FULL project competency list (binding: "nuovo candidato:
 * tutte le competenze presenti con liste risposte vuote"), LEFT JOINed to
 * `interview_sessions` on `(participant_id, competency_code)` so a competency with no
 * session yet still appears — with `status: 'pending'` and `answers: []` — rather than
 * being silently omitted.
 *
 * `answers` derives from the session's `question_index` + `ended_at`
 * (`…100002_create_interview_sessions_table.php:47,50,72`): a session with no
 * `ended_at` yields an empty answers array, regardless of its live `status` (e.g. a
 * session that is `in_corso` has not yet produced an "answer" in the payload sense —
 * competency completion is the domain-meaningful boundary, design.md D2).
 *
 * All reads use `withoutGlobalScopes()` / a raw query builder over `project_id` — this
 * class is designed to run from a queued job or a synchronous HTTP-request listener
 * with no reliable ambient tenant context (design.md D4); the caller is responsible
 * for resolving a tenant-safe $participantId.
 *
 * REQ: Payload assembly — progress event (C10 D7)
 */
final class ProgressPayloadAssembler
{
    /**
     * @return array<string, mixed>
     */
    public function assemble(int $participantId, string $deliveryId): array
    {
        $participant = Participant::findOrFail($participantId);
        $project = Project::withoutGlobalScopes()->findOrFail($participant->project_id);

        $rows = DB::table('project_competencies')
            ->join('framework_competencies', 'project_competencies.competency_id', '=', 'framework_competencies.id')
            ->leftJoin('interview_sessions', function ($join) use ($participantId): void {
                $join->on('interview_sessions.competency_code', '=', 'framework_competencies.code')
                    ->where('interview_sessions.participant_id', '=', $participantId);
            })
            ->where('project_competencies.project_id', $project->id)
            ->orderBy('project_competencies.position')
            ->select([
                'framework_competencies.code as code',
                'interview_sessions.status as session_status',
                'interview_sessions.question_index as question_index',
                'interview_sessions.ended_at as ended_at',
            ])
            ->get();

        $competencies = $rows->map(function (object $row): array {
            $answers = [];

            if ($row->ended_at !== null) {
                $answers[] = [
                    'question_index' => (int) $row->question_index,
                    'answered_at' => Carbon::parse($row->ended_at)->utc()->toIso8601String(),
                ];
            }

            return [
                'code' => $row->code,
                'status' => $row->session_status ?? 'pending',
                'answers' => $answers,
            ];
        })->all();

        return [
            'version' => (string) config('webhooks.payload.version'),
            'event' => WebhookEventType::Progress->value,
            'delivery_id' => $deliveryId,
            'occurred_at' => now()->utc()->toIso8601String(),
            'candidate_ref' => $participant->candidate_ref,
            'project' => [
                'id' => $project->id,
                'slug' => $project->slug,
            ],
            'data' => [
                'competencies' => $competencies,
            ],
        ];
    }
}
