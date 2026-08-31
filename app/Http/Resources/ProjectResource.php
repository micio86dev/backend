<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Competency;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ProjectResource (C4 Project Configuration).
 *
 * Serializes a Project for API responses.
 *
 * IMPORTANT: webhook_secret MUST be excluded.
 *   - It is in $hidden (excluded from toArray/toJson).
 *   - It is cast to 'encrypted' (DB-level encryption).
 *   - This resource MUST NOT access $this->webhook_secret — the attribute is hidden
 *     AND encrypted; its absence from the output is deliberate and security-critical.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * `status`/`assessment_type` are unions closed by the model's own
     * `booted()` guards (`Project.php:118-158`) — a status transition or an
     * immutable-field change outside the allowed set throws. `role_code`
     * stays plain nullable string (data owned by the wrapper superproject,
     * design.md D1). Every id/FK/nullable-int is backed by an explicit
     * `(int)` cast; nullable ints use the ternary form so `null` never
     * becomes `0`.
     *
     * @return array{id: int, organization_id: int, framework_version_id: int, slug: string, name: string, assessment_type: 'standard'|'potential', role_code: string|null, language: string, status: 'draft'|'active'|'archived', pause_every_n_competencies: int|null, nudge_min_chars: int|null, exit_redirect_url: string|null, avatar_template_id: int|null, webhook_url: string|null, webhook_events: list<string>, has_webhook_secret: bool, deadline_at: string|null, goes_live_at: string|null, created_at: string|null, updated_at: string|null, pin_context: array{id: int, version: string, label: string|null, is_locked: bool}|null, competencies: list<array{id: int, code: string, type: string, position: int}>}
     *
     * @scramble-return array{id: int, organization_id: int, framework_version_id: int, slug: string, name: string, assessment_type: 'standard'|'potential', role_code: string|null, language: string, status: 'draft'|'active'|'archived', pause_every_n_competencies: int|null, nudge_min_chars: int|null, exit_redirect_url: string|null, avatar_template_id: int|null, webhook_url: string|null, webhook_events: list<string>, has_webhook_secret: bool, deadline_at: string|null, goes_live_at: string|null, created_at: string|null, updated_at: string|null, pin_context: array{id: int, version: string, label: string|null, is_locked: bool}|null, competencies: list<array{id: int, code: string, type: string, position: int}>}
     */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;

        return [
            'id' => (int) $project->id,
            'organization_id' => (int) $project->organization_id,
            'framework_version_id' => (int) $project->framework_version_id,
            'slug' => $project->slug,
            'name' => $project->name,
            'assessment_type' => $project->assessment_type,
            'role_code' => $project->role_code,
            'language' => $project->language,
            'status' => $project->status,
            'pause_every_n_competencies' => $project->pause_every_n_competencies === null
                ? null
                : (int) $project->pause_every_n_competencies,
            'nudge_min_chars' => $project->nudge_min_chars === null
                ? null
                : (int) $project->nudge_min_chars,
            'exit_redirect_url' => $project->exit_redirect_url,
            // Null means "no template pinned — the organization's active one
            // applies", NOT "no template will be used". The backoffice needs
            // the two states distinguishable to label the control honestly.
            'avatar_template_id' => $project->avatar_template_id,
            'webhook_url' => $project->webhook_url,
            'webhook_events' => $project->webhook_events,
            // webhook_secret intentionally excluded (hidden + encrypted).
            // A PRESENCE BOOLEAN is exposed instead, never the value — mirroring
            // OrganizationResource::has_default_webhook_secret. Without it the
            // edit form cannot distinguish "no secret configured" from "a secret
            // exists but is write-only", so it renders "not set" over a project
            // that has one. On a security-relevant field that is not a cosmetic
            // gap: it invites an operator to believe no secret is in place.
            'has_webhook_secret' => $project->getRawOriginal('webhook_secret') !== null,
            'deadline_at' => $project->deadline_at?->toISOString(),
            'goes_live_at' => $project->goes_live_at?->toISOString(),
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
            // Pin context: the FrameworkVersion this project is pinned to
            'pin_context' => $project->frameworkVersion ? [
                'id' => (int) $project->frameworkVersion->id,
                'version' => $project->frameworkVersion->version,
                'label' => $project->frameworkVersion->label,
                'is_locked' => $project->frameworkVersion->is_locked,
            ] : null,
            // Competencies with position pivot
            'competencies' => $project->relationLoaded('competencies')
                ? array_values($project->competencies->map(fn (Competency $c): array => [
                    'id' => (int) $c->id,
                    'code' => $c->code,
                    'type' => $c->type,
                    'position' => (int) $c->pivot->getAttribute('position'),
                ])->all())
                : [],
        ];
    }
}
