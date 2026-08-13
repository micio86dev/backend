<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\InterviewSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a participant's session list (C11).
 *
 * Deliberately thin: it exists so an operator can pick which session to review,
 * not to be a review of its own. Loading integrity events and minting signed
 * snapshot URLs for every session of every participant would make a list view
 * pay for detail nobody asked for yet.
 *
 * @mixin InterviewSession
 */
final class SessionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $duration = $this->started_at !== null && $this->ended_at !== null
            ? $this->ended_at->getTimestamp() - $this->started_at->getTimestamp()
            : null;

        return [
            'id' => $this->id,
            'competency_code' => $this->competency_code,
            'question_index' => $this->question_index,
            'provider' => $this->provider,
            'status' => $this->status,
            'ended_reason' => $this->ended_reason,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $duration,
            'integrity_event_count' => $this->integrity_events_count ?? 0,
        ];
    }
}
