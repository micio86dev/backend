<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\InterviewSession;
use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin ParticipantDetailResource — Summary scope (C11 D5).
 *
 * Serializes a Participant for `GET /api/participants/{id}`: identity fields
 * plus a lifecycle timeline (`started_at`, `completed_at`, session count) and
 * the `files` open map (D9). RBAC-only — no lifecycle threshold (D2's
 * Summary scope).
 *
 * `files` (wired in PR A3, once ParticipantDownloadController's named routes
 * exist to generate real values — deferred from A2, which could only have
 * invented untested `ref`/`url` values): keyed by type, open for a future
 * `audio` key without a shape change (D9). Each `url` is ALWAYS present —
 * gating is identical to the corresponding read endpoint (same
 * AdminParticipantReader::read() call inside the download controller), so a
 * pre-threshold request to the URL returns 409, not a missing/absent link.
 * Per-question audio has no key here: it does not exist and is gated by open
 * product decision #2 (spec: "No audio download endpoint exists").
 *
 * SCOPE BOUNDARY: Summary-scope ONLY — MUST NEVER carry Transcript or
 * Evaluation fields (`utterances`, `reliability`, `behaviors`, `score`).
 *
 * @mixin Participant
 */
class ParticipantDetailResource extends JsonResource
{
    /**
     * As `Admin\ParticipantResource` (design.md D1 — `status` union closed by
     * `Participant::$allowedTransitions`; `role_code` plain nullable string;
     * `id`/`project_id`/`timeline.session_count` backed by explicit `(int)`
     * casts).
     *
     * `project` (operator-interview-link, design D5): the same three gate
     * fields `ProjectResource` already carries (`status`, `goes_live_at`,
     * `deadline_at`), plus `id`/`name` — lets the backoffice disable the
     * "Generate new link" action with a stated reason instead of offering an
     * action guaranteed to fail server-side. `id`/`name`/`goes_live_at`/
     * `deadline_at` move in lockstep across BOTH this docblock and the
     * `@scramble-return` below, or the exported schema lies (design D5).
     *
     * @return array{id: int, candidate_ref: string, display_name: string, role_code: string|null, language: string|null, status: 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore', project_id: int, project: array{id: int, name: string, status: 'draft'|'active'|'archived', goes_live_at: string|null, deadline_at: string|null}, timeline: array{started_at: string|null, completed_at: string|null, session_count: int}, files: array{transcript: array{type: string, ref: string, url: string}, evaluation_raw: array{type: string, ref: string, url: string}}, created_at: string|null}
     *
     * @scramble-return array{id: int, candidate_ref: string, display_name: string, role_code: string|null, language: string|null, status: 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore', project_id: int, project: array{id: int, name: string, status: 'draft'|'active'|'archived', goes_live_at: string|null, deadline_at: string|null}, timeline: array{started_at: string|null, completed_at: string|null, session_count: int}, files: array{transcript: array{type: string, ref: string, url: string}, evaluation_raw: array{type: string, ref: string, url: string}}, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var Participant $participant */
        $participant = $this->resource;
        // firstOrFail(), not the magic `->project` accessor: `project_id` is
        // a required FK, but BelongsTo::getResults() is typed nullable
        // (PHPStan/Larastan), which a bare property access cannot narrow.
        // firstOrFail() both satisfies the type checker honestly and throws
        // a clear exception instead of a null-pointer crash on the
        // pathological case of an orphaned FK.
        $project = $participant->project()->firstOrFail();

        return [
            'id' => (int) $participant->id,
            'candidate_ref' => $participant->candidate_ref,
            'display_name' => $participant->display_name,
            'role_code' => $participant->role_code,
            'language' => $participant->language,
            'status' => $participant->status,
            'project_id' => (int) $participant->project_id,
            'project' => [
                'id' => (int) $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'goes_live_at' => $project->goes_live_at?->toISOString(),
                'deadline_at' => $project->deadline_at?->toISOString(),
            ],
            'timeline' => [
                'started_at' => $participant->started_at?->toISOString(),
                'completed_at' => $participant->completed_at?->toISOString(),
                'session_count' => (int) InterviewSession::where('participant_id', $participant->id)->count(),
            ],
            'files' => [
                'transcript' => [
                    'type' => 'text/plain',
                    'ref' => 'transcript',
                    'url' => route('admin.participants.transcript.download', $participant->id),
                ],
                'evaluation_raw' => [
                    'type' => 'application/json',
                    'ref' => 'evaluation',
                    'url' => route('admin.participants.evaluation.download', $participant->id),
                ],
            ],
            'created_at' => $participant->created_at->toISOString(),
        ];
    }
}
