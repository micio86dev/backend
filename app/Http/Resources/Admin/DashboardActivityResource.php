<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the dashboard's recent-activity feed (C11, DESIGN.md §8.2).
 *
 * Carries `project_name` rather than a project id: this feed exists to be read
 * at a glance, and a row that forces the reader to look up which project a
 * candidate belongs to has failed at the one job it has.
 *
 * `display_name` is the operator-facing label; `candidate_ref` is the opaque
 * identifier the calling system owns and echoes back in every webhook, kept
 * here so a row can be correlated with that system without a second query.
 * Neither is contact data — BEAI holds none (CLAUDE.md, ruling 8).
 *
 * @mixin Participant
 */
final class DashboardActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'candidate_ref' => $this->candidate_ref,
            'display_name' => $this->display_name,
            'status' => $this->status,
            'project_name' => $this->project?->name,
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
