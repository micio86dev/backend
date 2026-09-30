<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use App\Models\Project;

/**
 * How many organizations and projects pin each platform avatar template.
 *
 * A cross-tenant aggregate, so it follows the same doctrine as the readers
 * under `App\Support\Superadmin\`: ONE grouped query whatever the number of
 * organizations, no per-organization loop, and counts only — no organization
 * name or id ever leaves this class. It lives here rather than there because
 * `CrossTenantReaderInventoryArchTest` pins that folder to exactly two
 * classes; `AvatarTemplateScopeEntryPointsArchTest` pins THIS one instead.
 *
 * The soft-delete scope is deliberately kept: a trashed project is invisible
 * and does not block deleting its template, and this is exactly the predicate
 * the model's `deleting` hook counts.
 */
final class GlobalAvatarTemplateUsage
{
    /**
     * @param  list<int>  $templateIds
     * @return array<int, array{organization_count: int, project_count: int}> keyed by template id, zeros for an unused one
     */
    public function for(array $templateIds): array
    {
        if ($templateIds === []) {
            return [];
        }

        $usage = array_fill_keys($templateIds, ['organization_count' => 0, 'project_count' => 0]);

        $rows = Project::withoutGlobalScope('tenant')
            ->whereIn('avatar_template_id', $templateIds)
            ->groupBy('avatar_template_id')
            ->selectRaw('avatar_template_id, count(*) as project_count, count(distinct organization_id) as organization_count')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $usage[(int) $row->avatar_template_id] = [
                'organization_count' => (int) $row->organization_count,
                'project_count' => (int) $row->project_count,
            ];
        }

        return $usage;
    }
}
