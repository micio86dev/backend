<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Models\BarsIndicator;
use App\Support\Catalogue\CatalogueRevisionResolver;
use Illuminate\Database\Eloquent\Collection;

/**
 * Loads BARS indicators scoped by BOTH role_id AND competency_id — the SINGLE
 * shared lookup for C8 (conversation) and C9 (scoring) alike.
 *
 * The C8 RV-2 carve-out this class was born under — "C9's inline
 * competency-only query is left untouched" — is REVERSED: that duplicated,
 * role-less query was the root cause of cross-role indicator contamination in
 * scoring, and `ScoreEvaluationJob` now resolves through here.
 *
 * Cross-role correctness property: indicators for the same competency code but
 * a different role are never returned. This prevents cross-role contamination
 * — a correctness invariant, not cosmetic.
 *
 * `$roleId === null` is NOT an error state — it is the ROLE-LESS predicate for
 * `potential` assessments (MTG/LAT), whose rows carry `role_id IS NULL` because
 * a potential project has no `role_code` by rule. It is deliberately emitted as
 * `whereNull('role_id')`, never `where('role_id', null)`: PostgreSQL evaluates
 * `x = NULL` as UNKNOWN, which would silently return zero rows and turn every
 * potential competency into `role_no_bars`.
 *
 * GLOBAL: BarsIndicator is not tenant-scoped; no organization_id filter applied.
 * Framework version is pinned via project.framework_version_id at the call site;
 * this loader does not filter by framework_version_id (the table carries the
 * version implicitly via the seeder; cross-version isolation is enforced by
 * the composition layer).
 *
 * `$revisionId` (framework-catalogue-authoring PR1, D1; default changed PR3b,
 * H1): resolves indicators through `Project -> FrameworkVersion ->
 * revision_id`, never the single live catalogue — the explicit filter that
 * makes a role/competency pair from one revision unreachable through another
 * revision's ids, matching the composite-FK doctrine `rules.design` requires
 * ("every query scope must be explicit"). OPTIONAL: `role_id`/`competency_id`
 * are themselves revision-unique primary keys (a draft is a full row-set
 * clone, D1, never a delta reusing ids), so a caller that already resolved
 * them against the RIGHT revision does not strictly need to repeat the
 * filter. But omitting it is no longer "no filter at all" — the DEFAULT now
 * resolves the LATEST PUBLISHED revision (never a draft, never "any"),
 * matching H1's mandate that a no-argument call must still be safe by
 * construction. A caller that has a project MUST still pass its own pinned
 * revision explicitly (`SystemPromptComposer`/`InterviewController`, wired in
 * PR3b) — the default exists for callers with no project context and as a
 * defense-in-depth floor, not as an excuse to skip the explicit pin.
 *
 * The default resolves via `tryLatestPublished()`, never `latestPublished()`
 * (gga review finding): this method declares no `@throws`, and
 * `SystemPromptComposer::compose()`'s own `@throws` list names only
 * `CompositionException`/`AnchorTranslationMissingException` — the pair its
 * one caller (`InterviewController`) actually catches. A no-argument call on
 * an unseeded platform (no published revision at all) must resolve to an
 * IMPOSSIBLE id instead, so the query naturally returns an empty Collection
 * — which `SystemPromptComposer::compose()` already turns into
 * `CompositionException` for an empty indicator set — rather than an
 * uncaught 500 escaping both this class and its caller's declared contract.
 *
 * REQ: BarsIndicatorLoader (C8 RV-2)
 */
final class BarsIndicatorLoader
{
    /**
     * Return all BARS indicators for the given role AND competency, ordered by position.
     *
     * @param  int|null  $roleId  Role primary key (from project.role_code → Role.id), or
     *                            null for a role-less (`potential`) lookup — emitted as
     *                            `whereNull('role_id')`, never a null-bound `=` comparison.
     * @param  int  $competencyId  Competency primary key.
     * @param  int|null  $revisionId  Scopes indicators to this catalogue revision — a
     *                                role/competency pair whose rows belong to a DIFFERENT
     *                                revision resolves to an empty Collection rather than
     *                                another revision's content. Omitted, resolves the
     *                                LATEST PUBLISHED revision (never "any revision").
     * @return Collection<int, BarsIndicator> Ordered by position ascending; may be empty.
     */
    public function forRoleCompetency(?int $roleId, int $competencyId, ?int $revisionId = null): Collection
    {
        $revisionId ??= app(CatalogueRevisionResolver::class)->tryLatestPublished() ?? CatalogueRevisionResolver::NO_PUBLISHED_REVISION;

        $query = BarsIndicator::where('competency_id', $competencyId)
            ->where('revision_id', $revisionId);

        if ($roleId === null) {
            $query->whereNull('role_id');
        } else {
            $query->where('role_id', $roleId);
        }

        /** @var Collection<int, BarsIndicator> */
        return $query->orderBy('position')->get();
    }
}
