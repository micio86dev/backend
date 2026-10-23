<?php

declare(strict_types=1);

namespace App\Actions\AvatarTemplates;

use App\Models\AvatarTemplate;
use App\Support\Tenancy\TenantContextScope;

/**
 * The per-organization work of `hr-avatar:cutover`.
 *
 * Everything runs inside `TenantContextScope::runFor($organizationId)` through the ordinary tenant-scoped
 * Eloquent queries: no global scope is stripped, so a lookup can only ever match the current organization's live,
 * organization-owned rows (platform and soft-deleted rows are out by construction).
 *
 * Rows are plain arrays: `outcome`'s first token is the kind the command counts.
 */
final class CutoverToHrAvatar
{
    private const SOURCE_PROVIDERS = ['tavus', 'heygen'];

    private const NAME_MAX = 120;

    /**
     * @return list<array{line: string, outcome: string}>
     */
    public function cutover(int $organizationId): array
    {
        return TenantContextScope::runFor($organizationId, function (): array {
            $rows = [];

            foreach (AvatarTemplate::whereIn('provider', self::SOURCE_PROVIDERS)->orderBy('id')->get() as $source) {
                $rows[] = [
                    'line' => sprintf('template=%d provider=%s name="%s"', $source->id, $source->provider, mb_substr($source->name, 0, self::NAME_MAX)),
                    'outcome' => $source->hr_avatar_config_id === null ? 'skipped not_exported' : 'would_twin',
                ];
            }

            return $rows;
        });
    }
}
