<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Models\BarsIndicator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Loads BARS indicators scoped by BOTH role_id AND competency_id.
 *
 * This class is C8-owned and MUST NOT reference or modify ScoreEvaluationJob
 * or any C9 code. C9's inline competency-only query is left untouched (RV-2).
 *
 * Cross-role correctness property: indicators for the same competency code but
 * a different role are never returned. This prevents cross-role contamination
 * — a correctness invariant, not cosmetic.
 *
 * GLOBAL: BarsIndicator is not tenant-scoped; no organization_id filter applied.
 * Framework version is pinned via project.framework_version_id at the call site;
 * this loader does not filter by framework_version_id (the table carries the
 * version implicitly via the seeder; cross-version isolation is enforced by
 * the composition layer).
 *
 * REQ: BarsIndicatorLoader (C8 RV-2)
 */
final class BarsIndicatorLoader
{
    /**
     * Return all BARS indicators for the given role AND competency, ordered by position.
     *
     * @param  int  $roleId  Role primary key (from project.role_code → Role.id).
     * @param  int  $competencyId  Competency primary key.
     * @return Collection<int, BarsIndicator> Ordered by position ascending; may be empty.
     */
    public function forRoleCompetency(int $roleId, int $competencyId): Collection
    {
        /** @var Collection<int, BarsIndicator> */
        return BarsIndicator::where('role_id', $roleId)
            ->where('competency_id', $competencyId)
            ->orderBy('position')
            ->get();
    }
}
