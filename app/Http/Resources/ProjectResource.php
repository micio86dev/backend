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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;

        return [
            'id' => $project->id,
            'organization_id' => $project->organization_id,
            'framework_version_id' => $project->framework_version_id,
            'slug' => $project->slug,
            'name' => $project->name,
            'assessment_type' => $project->assessment_type,
            'role_code' => $project->role_code,
            'language' => $project->language,
            'status' => $project->status,
            'pause_every_n_competencies' => $project->pause_every_n_competencies,
            'nudge_min_chars' => $project->nudge_min_chars,
            'exit_redirect_url' => $project->exit_redirect_url,
            'webhook_url' => $project->webhook_url,
            // webhook_secret intentionally excluded (hidden + encrypted)
            'deadline_at' => $project->deadline_at?->toISOString(),
            'goes_live_at' => $project->goes_live_at?->toISOString(),
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
            // Pin context: the FrameworkVersion this project is pinned to
            'pin_context' => $project->frameworkVersion ? [
                'id' => $project->frameworkVersion->id,
                'version' => $project->frameworkVersion->version,
                'label' => $project->frameworkVersion->label,
                'is_locked' => $project->frameworkVersion->is_locked,
            ] : null,
            // Competencies with position pivot
            'competencies' => $project->relationLoaded('competencies')
                ? $project->competencies->map(fn (Competency $c): array => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'type' => $c->type,
                    'position' => $c->pivot->getAttribute('position'),
                ])->values()->toArray()
                : [],
        ];
    }
}
