<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Enums\ApiKeyMode;
use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Services\Admin\ParticipantInterviewAggregator;
use App\Support\Admin\ReusableLinkOrigin;
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
     * `reusable_link` (reusable-interview-links, B4): as `Admin\ParticipantResource`,
     * an object with exactly the link's public id and label, or `null` for a
     * participant that did not come from a reusable link. Moves in lockstep
     * across BOTH docblocks, or the exported schema lies. Loaded by
     * `ParticipantController::show()`, not by the shared reader, which also
     * serves the transcript and evaluation reads.
     *
     * `progress`/`elapsed`/`cost` (operator-participant-visibility D3/D4/D6):
     * the five missing facts, derived once by `ParticipantInterviewAggregator`
     * over one pass of the participant's InterviewSession rows.
     * `elapsed.seconds`/`cost.amount` are `null` (never `0`) when nothing
     * could be measured/estimated — each figure carries its own
     * `sessions_*` coverage counts because cost and elapsed genuinely
     * exclude different sessions.
     *
     * `retry_attempt`/`retry_authorized_at`/`retry_available` (scoring-retry-rt-b):
     * the read-only state of the single evaluation re-interview, from the
     * participant's own Evaluation. Machine values, never localized, and never
     * anything of the pending evaluation itself (it stays unreadable until the
     * participant is back at `completato`). The phase of an authorized retry is
     * derived by the client from the literal `status`. `retry_available` is the
     * eligibility the action enforces, minus the project's entry gates, which the
     * action's 409 reports. Moves in lockstep across BOTH docblocks.
     *
     * @return array{id: int, candidate_ref: string, display_name: string, email: string, external_id: int|null, source: string|null, reusable_link: array{id: string, label: string|null}|null, role_code: string|null, language: string|null, status: 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore', project_id: int, project: array{id: int, name: string, status: 'draft'|'active'|'archived', goes_live_at: string|null, deadline_at: string|null}, timeline: array{started_at: string|null, completed_at: string|null, session_count: int}, progress: array{done: int, total: int}, elapsed: array{seconds: int|null, sessions_counted: int, sessions_total: int}, cost: array{amount: float|null, currency: string, is_estimate: bool, sessions_estimated: int, sessions_total: int}, files: array{transcript: array{type: string, ref: string, url: string}, evaluation_raw: array{type: string, ref: string, url: string}}, created_at: string|null, retry_attempt: bool, retry_authorized_at: string|null, retry_available: bool}
     *
     * @scramble-return array{id: int, candidate_ref: string, display_name: string, email: string, external_id: int|null, source: string|null, reusable_link: array{id: string, label: string|null}|null, role_code: string|null, language: string|null, status: 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore', project_id: int, project: array{id: int, name: string, status: 'draft'|'active'|'archived', goes_live_at: string|null, deadline_at: string|null}, timeline: array{started_at: string|null, completed_at: string|null, session_count: int}, progress: array{done: int, total: int}, elapsed: array{seconds: int|null, sessions_counted: int, sessions_total: int}, cost: array{amount: float|null, currency: string, is_estimate: bool, sessions_estimated: int, sessions_total: int}, files: array{transcript: array{type: string, ref: string, url: string}, evaluation_raw: array{type: string, ref: string, url: string}}, created_at: string|null, retry_attempt: bool, retry_authorized_at: string|null, retry_available: bool}
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

        $interview = (new ParticipantInterviewAggregator)->aggregate($participant);

        // Tenant-scoped by the ambient TenantContext, like every other read here.
        $evaluation = Evaluation::where('participant_id', $participant->id)->first();
        $retryAttempt = $evaluation?->retry_attempt === true;

        return [
            'id' => (int) $participant->id,
            'candidate_ref' => $participant->candidate_ref,
            'display_name' => $participant->display_name,
            // Operator-facing, and admin-side only. It is the candidate's
            // identity across projects as well as the address an invitation
            // goes to, so an operator re-issuing a link needs to see WHICH
            // person they are re-issuing it for. Never exposed on the
            // candidate-facing resource — telling someone their own address
            // back adds nothing and widens what a stolen token discloses.
            'email' => $participant->email,
            // The calling system's own identifiers: see
            // `Admin\ParticipantResource`. Moved in lockstep across both
            // docblocks above, or the exported schema lies.
            'external_id' => $participant->external_id,
            'source' => $participant->source,
            'reusable_link' => ReusableLinkOrigin::of($participant),
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
            'progress' => $interview['progress'],
            'elapsed' => $interview['elapsed'],
            'cost' => $interview['cost'],
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
            'retry_attempt' => $retryAttempt,
            'retry_authorized_at' => $evaluation?->retry_authorized_at?->toISOString(),
            // The action's own guards, in one expression: a test-mode participant
            // is never scored by the real job, so the action refuses it.
            'retry_available' => ! $retryAttempt
                && $participant->status === 'completato'
                && $participant->mode !== ApiKeyMode::Test
                && $evaluation?->status === EvaluationStatus::Pending,
        ];
    }
}
