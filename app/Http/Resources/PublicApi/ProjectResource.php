<?php

declare(strict_types=1);

namespace App\Http\Resources\PublicApi;

use App\Models\Project;
use App\PublicApi\Serializers\ProjectSerializer;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin HTTP wrapper around `App\PublicApi\Serializers\ProjectSerializer`
 * for `GET /v1/projects` and `GET /v1/projects/{id}` (public-api step 4).
 *
 * `#[SchemaName('PublicProject')]` — same reasoning as `App\Http\Resources\
 * PublicApi\OrganizationResource`'s own docblock: this class's bare
 * basename collides with the ADMIN `App\Http\Resources\ProjectResource`,
 * and naming this one explicitly is what keeps the admin schema's name
 * (`ProjectResource`) stable in the exported spec regardless of which
 * class Scramble happens to process first.
 *
 * @mixin Project
 */
#[SchemaName('PublicProject')]
final class ProjectResource extends JsonResource
{
    /**
     * The contract's `getProject` response body IS the `Project` schema
     * directly, and `listProjects`'s `data[]` items are each a bare
     * `Project` too — no per-item `data` envelope. See
     * `OrganizationResource::$wrap`'s own docblock for why this is
     * disabled per-class rather than globally.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{id: string, name: string, slug: string, role_code: string|null, assessment_type: 'standard'|'potential', language: string, status: 'draft'|'active'|'archived', framework_version: array{version: string, label: string|null}, competencies: list<array{code: string, name: string, type: string}>, pause_every_n_competencies: int|null, nudge_min_chars: int|null, exit_redirect_url: string|null, avatar_display_name: string|null, created_at: string, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;

        return ProjectSerializer::toArray($project);
    }
}
