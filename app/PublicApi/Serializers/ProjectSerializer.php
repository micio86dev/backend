<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Models\Competency;
use App\Models\Project;
use App\Support\PublicApi\PublicId;

/**
 * Public-safe `Project` shape for the BEAI Public API (`/v1`) and, later,
 * the bulk export (public-api step 4/8 both share this class — SPEC.md
 * §3.4 "Implement one serializer per resource shared by the public API and
 * the export").
 *
 * EXACTLY the §3.4 field list — nothing more: `id, name, slug, role_code,
 * assessment_type, language, status, framework_version {version, label},
 * competencies[] {code, name, type}, pause_every_n_competencies,
 * nudge_min_chars, exit_redirect_url, avatar_display_name, created_at,
 * updated_at`. Deliberately never reads `provider`, `config`, `persona`,
 * `error_redirect_url`, webhook configuration, `deadline_at`,
 * `goes_live_at`, `pin_context` or `can` — see the exclusion list in
 * SPEC.md §3.4.
 *
 * Framework-light by design (no HTTP concerns) so the export step can call
 * `toArray()` directly outside any HTTP request lifecycle.
 */
final class ProjectSerializer
{
    /**
     * `$project` should have `frameworkVersion`, `avatarTemplate` and
     * `competencies` eager-loaded — every real call site (the two `/v1`
     * controllers) does. An unloaded `avatarTemplate` renders
     * `avatar_display_name: null` (the same "absent relation renders as
     * absent, never fatal" discipline `ProjectResource::avatar_template`
     * already documents) rather than triggering a lazy-load.
     *
     * @return array{id: string, name: string, slug: string, role_code: string|null, assessment_type: 'standard'|'potential', language: string, status: 'draft'|'active'|'archived', framework_version: array{version: string, label: string|null}, competencies: list<array{code: string, name: string, type: string}>, pause_every_n_competencies: int|null, nudge_min_chars: int|null, exit_redirect_url: string|null, avatar_display_name: string|null, created_at: string, updated_at: string}
     */
    public static function toArray(Project $project): array
    {
        return [
            'id' => PublicId::encode($project),
            'name' => $project->name,
            'slug' => $project->slug,
            'role_code' => $project->role_code,
            'assessment_type' => $project->assessment_type,
            'language' => $project->language,
            'status' => $project->status,
            'framework_version' => [
                // `framework_version_id` is NOT NULL, but the relation
                // accessor's static type is still nullable (an unloaded
                // relation renders null rather than fatal, the same
                // discipline `Project::avatarTemplate()`'s own docblock
                // documents) — an empty string/null pair is the honest
                // answer when the caller forgot to eager-load it, never a
                // fatal error on a read-only endpoint.
                'version' => $project->frameworkVersion->version ?? '',
                'label' => $project->frameworkVersion->label ?? null,
            ],
            'competencies' => $project->relationLoaded('competencies')
                ? array_values($project->competencies->map(function (Competency $competency) use ($project): array {
                    // In the PROJECT's own language (SPEC.md §3.4 "name ...
                    // In the project language"), never the request's
                    // current app locale — `getTranslation()` bypasses
                    // `HasTranslations::getAttributeValue()`'s
                    // current-locale resolution entirely. Its return type is
                    // genuinely `mixed` (Spatie's own signature); a
                    // translation value on this attribute is always a
                    // string in practice, but this reads it defensively
                    // rather than casting a `mixed` value.
                    $name = $competency->getTranslation('name', $project->language);

                    return [
                        'code' => $competency->code,
                        'name' => is_string($name) ? $name : '',
                        'type' => $competency->type,
                    ];
                })->all())
                : [],
            'pause_every_n_competencies' => $project->pause_every_n_competencies === null
                ? null
                : (int) $project->pause_every_n_competencies,
            'nudge_min_chars' => $project->nudge_min_chars === null
                ? null
                : (int) $project->nudge_min_chars,
            'exit_redirect_url' => $project->exit_redirect_url,
            'avatar_display_name' => $project->avatarTemplate?->name,
            'created_at' => (string) $project->created_at?->toISOString(),
            'updated_at' => (string) $project->updated_at?->toISOString(),
        ];
    }
}
