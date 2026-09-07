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
 * `id` addresses the candidate inside this product and is what the feed links
 * on; `display_name` is the operator-facing label; `candidate_ref` is the opaque
 * identifier the calling system owns and echoes back in every webhook, kept
 * here so a row can be correlated with that system without a second query.
 * Neither is contact data — BEAI holds none (CLAUDE.md, ruling 8).
 *
 * @mixin Participant
 */
final class DashboardActivityResource extends JsonResource
{
    /**
     * The shape is spelled out rather than left as `array<string, mixed>`, and
     * `project_name` is declared NULLABLE on purpose.
     *
     * `$this->project?->name` makes null genuinely reachable, and Scramble does
     * not infer nullability from a nullsafe call — so the published contract
     * said `project_name: string`, non-nullable, while the API could and did
     * return null. Every consumer generated a type that was wrong, and the
     * backoffice hid it by hand-writing `string | null` instead of importing
     * the generated one: the drift was absorbed silently rather than failing a
     * typecheck and pointing here.
     *
     * `display_name` stays NON-nullable, and that distinction is the point.
     * Widening it alongside `project_name` would have been the mirror image of
     * the bug being fixed: `participants.display_name` is NOT NULL in the
     * migration, the model declares `@property string`, and
     * `Admin\ParticipantResource` publishes `string` for the same column on the
     * same model. Declaring it nullable forces every generated client to null-
     * check a value the API cannot return, and leaves the next reader unable to
     * tell which of the two fields is telling the truth. One lie removed, one
     * added.
     *
     * BOTH tags, matching `ApiClientResource`: `@return` is what PHPStan reads,
     * `@scramble-return` is what the exporter reads. The `@return` alone left
     * the spec unchanged — verified by regenerating and diffing.
     *
     * @return array{id: int, candidate_ref: string, display_name: string, status: string, project_name: string|null, updated_at: string}
     *
     * @scramble-return array{id: int, candidate_ref: string, display_name: string, status: string, project_name: string|null, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            // The row's own id, so the feed can LINK to the candidate instead
            // of naming them. `candidate_ref` below is the calling system's
            // opaque identifier and addresses nothing in this product — a feed
            // that says who just moved and gives no way to go and look is a
            // page you read and then leave to use the search box.
            'id' => (int) $this->id,
            'candidate_ref' => $this->candidate_ref,
            'display_name' => $this->display_name,
            'status' => $this->status,
            'project_name' => $this->project?->name,
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
