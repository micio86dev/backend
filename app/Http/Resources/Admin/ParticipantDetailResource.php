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
 * plus a lifecycle timeline (`started_at`, `completed_at`, session count).
 * RBAC-only — no lifecycle threshold (D2's Summary scope).
 *
 * Deferred from this PR (A2), flagged honestly: D5/D9's `files` open map
 * (`{ transcript: {type, ref, url}, evaluation_raw: {...} }`) is NOT included
 * here. Building it now would require either inventing untested `ref`/`url`
 * values or calling `route()` against download routes that do not exist yet
 * (they land in PR A3, Phase 8). Adding it here would be either fabricated or
 * broken. It is added in A3 once `ParticipantDownloadController` and its
 * routes exist to generate real values.
 *
 * SCOPE BOUNDARY: Summary-scope ONLY — MUST NEVER carry Transcript or
 * Evaluation fields (`utterances`, `reliability`, `behaviors`, `score`).
 *
 * @mixin Participant
 */
class ParticipantDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Participant $participant */
        $participant = $this->resource;

        return [
            'id' => $participant->id,
            'candidate_ref' => $participant->candidate_ref,
            'display_name' => $participant->display_name,
            'role_code' => $participant->role_code,
            'language' => $participant->language,
            'status' => $participant->status,
            'project_id' => $participant->project_id,
            'timeline' => [
                'started_at' => $participant->started_at?->toISOString(),
                'completed_at' => $participant->completed_at?->toISOString(),
                'session_count' => InterviewSession::where('participant_id', $participant->id)->count(),
            ],
            'created_at' => $participant->created_at->toISOString(),
        ];
    }
}
