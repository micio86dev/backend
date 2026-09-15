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
 * `$revisionId` (framework-catalogue-authoring PR1, D1): resolves indicators
 * through `Project -> FrameworkVersion -> revision_id`, never the single live
 * catalogue — the explicit filter that makes a role/competency pair from one
 * revision unreachable through another revision's ids, matching the
 * composite-FK doctrine `rules.design` requires ("every query scope must be
 * explicit"). OPTIONAL, and defaults to no filter: `role_id`/`competency_id`
 * are themselves revision-unique primary keys (a draft is a full row-set
 * clone, D1, never a delta reusing ids), so every EXISTING caller — none of
 * which knows about revisions yet — keeps its exact current behaviour.
 * Threading a real revision id through the live interview call path
 * (`SystemPromptComposer` / `InterviewController`) is D7/D8's job (PR7), not
 * this one.
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
     * @param  int|null  $revisionId  When given, indicators are additionally scoped to this
     *                                catalogue revision — a role/competency pair whose rows
     *                                belong to a DIFFERENT revision resolves to an empty
     *                                Collection rather than another revision's content.
     * @return Collection<int, BarsIndicator> Ordered by position ascending; may be empty.
     */
    public function forRoleCompetency(int $roleId, int $competencyId, ?int $revisionId = null): Collection
    {
        $query = BarsIndicator::where('role_id', $roleId)
            ->where('competency_id', $competencyId);

        if ($revisionId !== null) {
            $query->where('revision_id', $revisionId);
        }

        /** @var Collection<int, BarsIndicator> */
        return $query->orderBy('position')->get();
    }
}
