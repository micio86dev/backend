<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\ApiKeyMode;
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
 * Reads use `withoutGlobalScope('tenant')` / a raw query builder over `project_id` —
 * this class is designed to run from a queued job or a synchronous HTTP-request
 * listener with no reliable ambient tenant context (design.md D4). `assemble()` itself
 * scopes the `Participant` read by the caller-supplied `$organizationId` (pre-commit
 * gate, round 4, finding 3) — never trusted implicitly from `$participantId` alone.
 *
 * REQ: Payload assembly — progress event (C10 D7)
 */
final class ProgressPayloadAssembler
{
    /**
     * `$organizationId` (pre-commit gate, round 4, finding 3): threaded through from
     * the caller, which resolves it from the SAME project the event already names —
     * see `App\Listeners\SendProgressWebhook::resolveOrganizationId()`. `Participant`
     * is a PLAIN model (class doc), never auto-scoped by a global scope; an unscoped
     * `findOrFail()` here would happily resolve a participant belonging to a
     * DIFFERENT organization than the caller's own, silently rendering that other
     * organization's candidate data into this webhook payload.
     *
     * @return array<string, mixed>
     */
    public function assemble(int $participantId, int $organizationId, string $deliveryId): array
    {
        $participant = Participant::where('organization_id', $organizationId)->findOrFail($participantId);
        // withoutGlobalScope('tenant') ONLY, never the plural no-args form
        // (pre-commit gate round 7, finding 1): $participant is already
        // org-scoped above, so this only ever reaches that same
        // organization's project regardless; the singular form keeps
        // SoftDeletingScope so a soft-deleted project still 404s.
        $project = Project::withoutGlobalScope('tenant')->findOrFail($participant->project_id);

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
            // SPEC.md §3.7: a test-mode /v1 interview's webhooks must be
            // tellable apart from a real delivery — `false` for a
            // `beai_test_`-key-created participant, `true` otherwise.
            'livemode' => $participant->mode === ApiKeyMode::Live,
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
