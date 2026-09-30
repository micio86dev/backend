<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AvatarTemplate;
use Illuminate\Http\Request;

/**
 * A platform (global) avatar template as a superadmin sees it: every field of
 * {@see AvatarTemplateResource} plus how many organizations and projects pin it.
 *
 * `usage` is counts and nothing else — never an organization name or id — and is
 * handed in already computed, because the list computes it for every row in ONE
 * grouped query (`GlobalAvatarTemplateUsage`) and a resource that looked it up
 * itself would turn that back into one query per row. It is superadmin-only:
 * no organization role is ever served this resource.
 *
 * @mixin AvatarTemplate
 */
final class PlatformAvatarTemplateResource extends AvatarTemplateResource
{
    /**
     * @param  array{organization_count: int, project_count: int}  $usage
     */
    public function __construct(AvatarTemplate $resource, private readonly array $usage)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: int, name: string, description: string|null, provider: string, scope: 'organization'|'platform', config: array<string, mixed>, is_active: bool, created_at: string|null, updated_at: string|null, llm_model_id: int|null, llm_credential_id: int|null, llm_sync_status: string|null, llm_synced_at: string|null, llm: array{estimated_cost_usd_per_interview: array{minutes: int, turns: int, usd: float}|null}, pal_sync: array{status: string|null, code: string|null, synced_at: string|null}, usage: array{organization_count: int, project_count: int}}
     *
     * @scramble-return array{id: int, name: string, description: string|null, provider: string, scope: 'organization'|'platform', config: array<string, mixed>, is_active: bool, created_at: string|null, updated_at: string|null, llm_model_id: int|null, llm_credential_id: int|null, llm_sync_status: string|null, llm_synced_at: string|null, llm: array{estimated_cost_usd_per_interview: array{minutes: int, turns: int, usd: float}|null}, pal_sync: array{status: string|null, code: string|null, synced_at: string|null}, usage: array{organization_count: int, project_count: int}}
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'usage' => $this->usage];
    }
}
