<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin ParticipantResource — Summary scope (C11 D5).
 *
 * Serializes a Participant for `GET /api/participants` and the top of
 * `GET /api/participants/{id}` (RBAC-only, no lifecycle threshold — D2).
 *
 * SCOPE BOUNDARY: this resource is Summary-scope ONLY. It MUST NEVER carry
 * Transcript or Evaluation fields (`utterances`, `reliability`, `behaviors`,
 * `score`) — those require a separately-gated read
 * (AdminParticipantReader::read() with the matching ParticipantReadScope). A
 * Summary-scope resource that leaked wider-scope data would bypass the
 * lifecycle read-gate in practice, since Summary has no lifecycle threshold.
 *
 * @mixin Participant
 */
class ParticipantResource extends JsonResource
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
            'started_at' => $participant->started_at?->toISOString(),
            'completed_at' => $participant->completed_at?->toISOString(),
            'created_at' => $participant->created_at->toISOString(),
        ];
    }
}
