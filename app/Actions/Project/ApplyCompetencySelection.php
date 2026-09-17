<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Support\Settings\PlatformSettings;
use Illuminate\Database\Eloquent\Collection;

/**
 * Applies a project's competency selection to `project_questions`
 * (framework-catalogue-authoring PR5, D10).
 *
 * Invoked from exactly two places — `ProjectController::store()` and
 * `ProjectController::update()` — both INSIDE the `DB::transaction` those
 * actions already open. This class does not open its own: a failure
 * anywhere in here leaves the competency set and the question rows
 * consistent, the same property the caller's transaction already promises
 * for the rest of the write.
 *
 * OBSERVED, NEVER DIFFED. `$attached`/`$detached` come straight from
 * `BelongsToMany::sync()`'s own return value at each call site — diffing the
 * payload against a pre-read pivot snapshot would be a second, weaker
 * computation of what `sync()` already answers, and it would be WRONG under
 * a concurrent write. `sync()`'s `updated` bucket (a pure position change,
 * never a selection change) is deliberately never passed here.
 */
final class ApplyCompetencySelection
{
    public function __construct(
        private readonly PlatformSettings $platformSettings,
    ) {}

    /**
     * @param  list<int>  $attached  competency ids newly ADDED to the project's
     *                               competency set by this `sync()` call
     * @param  list<int>  $detached  competency ids newly REMOVED by this
     *                               `sync()` call
     */
    public function apply(Project $project, array $attached, array $detached): void
    {
        foreach ($detached as $competencyId) {
            $this->softDeleteLive($project, (int) $competencyId);
        }

        foreach ($attached as $competencyId) {
            $this->applyOneAttachedCompetency($project, (int) $competencyId);
        }
    }

    /**
     * PUBLIC (Z22, R3-interviewability-rollout-existing-projects,
     * framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE): the SAME
     * three-branch decision `apply()` runs for a newly-attached competency
     * (restore a deselection-trashed row, no-op if live rows already exist,
     * otherwise copy the catalogue defaults), exposed for
     * `beai:backfill-project-questions` to call directly. That command
     * exists because `ProjectInterviewability` can find an EXISTING
     * project's currently-selected competency with zero live questions —
     * one this class's own `apply()` was never invoked for, since the
     * competency was selected before this write path existed — and it must
     * reach the exact same decision `apply()` would make for a fresh
     * selection, not a second, independently-drifting copy of it. Calling
     * this for a competency that already has live questions is a safe
     * no-op (branch 2 below) — the caller does not need to pre-filter.
     */
    public function ensureCompetencyHasQuestions(Project $project, int $competencyId): void
    {
        $this->applyOneAttachedCompetency($project, $competencyId);
    }

    /**
     * Deselecting soft-deletes every LIVE row for the competency. Only the
     * live ones — a row already soft-deleted (e.g. individually removed by
     * the operator before deselection) stays exactly as it was.
     *
     * Z8 (R3-restore-resurrects-individually-deleted-question, REQUIRED
     * BEFORE ARCHIVE): stamps `deleted_by_deselection = true` in the SAME
     * `UPDATE` as the soft-delete itself (replicating what the SoftDeletes
     * builder's own `delete()` does internally, plus this one extra column,
     * rather than a second round trip) — `restore()` reads it to resurrect
     * only rows THIS write removed, never one an operator deleted on
     * purpose.
     */
    private function softDeleteLive(Project $project, int $competencyId): void
    {
        ProjectQuestion::where('project_id', $project->id)
            ->where('competency_id', $competencyId)
            ->update(['deleted_by_deselection' => true, 'deleted_at' => now()]);
    }

    /**
     * Three branches, checked IN ORDER and mutually exclusive by the first
     * one that matches — never combined, per D10:
     *
     *   1. A DESELECTION-trashed row exists (Z8: `deleted_by_deselection =
     *      true`) → restore it, stop. The operator's own text and
     *      provenance come back untouched; no default is copied. A row the
     *      operator deleted INDIVIDUALLY is invisible to this branch and
     *      stays trashed — see `restore()`'s own docblock.
     *   2. Live rows already exist → no-op (the idempotence scenario: a
     *      re-save with the same competency set must not duplicate rows).
     *   3. Neither → copy the catalogue defaults as a fresh snapshot.
     */
    private function applyOneAttachedCompetency(Project $project, int $competencyId): void
    {
        // Z8: scoped to `deleted_by_deselection = true` — a row an operator
        // deleted individually (the column's `false` default) is excluded
        // from `$trashed` entirely, so it is never touched by `restore()`
        // below and stays soft-deleted.
        $trashed = ProjectQuestion::onlyTrashed()
            ->where('project_id', $project->id)
            ->where('competency_id', $competencyId)
            ->where('deleted_by_deselection', true)
            ->orderBy('position')
            ->get();

        if ($trashed->isNotEmpty()) {
            $this->restore($project, $competencyId, $trashed);

            return;
        }

        $liveExists = ProjectQuestion::where('project_id', $project->id)
            ->where('competency_id', $competencyId)
            ->exists();

        if ($liveExists) {
            return;
        }

        $this->copyDefaults($project, $competencyId);
    }

    /**
     * Restores trashed rows for the competency, never touching `text` or
     * `operator_modified` — a position is a slot, not content, and the
     * spec's "restore exactly those rows" is about content.
     *
     * CAPPED, like a fresh copy. The platform's per-assessment-type ceiling
     * (`PlatformSettings::maxQuestionsPerCompetency()`) is a ceiling on LIVE
     * rows, restore included — a reselection must not resurrect more than
     * the cap allows purely because more than the cap were once live before
     * deselection. Restored in POSITION order, lowest first, up to the cap;
     * anything beyond it stays trashed.
     *
     * CLOSED (Z8, framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE):
     * `$trashed` (the caller's own query) is ALREADY scoped to
     * `deleted_by_deselection = true` — a row the operator deleted
     * individually before deselecting the competency never reaches this
     * method at all, and stays trashed regardless of the cap. When MORE
     * deselection-trashed rows exist than the cap allows, which of THOSE
     * come back is still decided by position alone — that residual is
     * about ordering among equals, not about resurrecting operator work
     * that was deleted on purpose.
     *
     * DEFENSIVE RENUMBER. `StoreProjectQuestionRequest` (18.2) already
     * closes the one known path to a collision with a LIVE row — authoring
     * a fresh question for a currently-unselected competency — so that
     * particular collision should be unreachable in the ordinary flow. It
     * stays anyway: restoring a row onto a position already occupied would
     * hit `project_questions_position_unique` and turn a working feature
     * into a 500. Any restored row whose original slot is occupied (by a
     * live row, or by an earlier row already restored in this same call —
     * two trashed rows CAN share a stored position, since the partial
     * unique index only ever governed the live ones) is reassigned the
     * next free position above the current maximum instead. That maximum
     * is recomputed after EVERY row, live or bumped — a stale counter here
     * previously let a second bumped row collide with the first.
     *
     * @param  Collection<int, ProjectQuestion>  $trashed  ordered by original position
     */
    private function restore(Project $project, int $competencyId, Collection $trashed): void
    {
        /** @var array<int, true> $occupied position => occupied, live rows only (the default query scope excludes trashed rows already) */
        $occupied = array_fill_keys(
            ProjectQuestion::where('project_id', $project->id)
                ->where('competency_id', $competencyId)
                ->pluck('position')
                ->all(),
            true,
        );

        $next = $occupied === [] ? 0 : max(array_keys($occupied)) + 1;

        $cap = $this->platformSettings->maxQuestionsPerCompetency((string) $project->assessment_type);
        $restored = 0;

        foreach ($trashed as $row) {
            if ($restored >= $cap) {
                break;
            }

            if (isset($occupied[$row->position])) {
                $row->position = $next;
            }

            $occupied[$row->position] = true;
            // Recomputed from the FULL occupied set on every iteration,
            // never incremented in isolation — the bug this replaced kept a
            // counter that only advanced on a bump, so a second row kept at
            // its OWN (now-occupied) original position was never accounted
            // for, and the next bump collided with it.
            $next = max(array_keys($occupied)) + 1;

            // Z8 (gga review finding, blocking): cleared, not left as-is —
            // a row now LIVE is no longer "trashed by a deselection", and
            // leaving `true` here reopened the exact bug this column exists
            // to close: restore → operator individually deletes the SAME
            // row → the flag is still `true` from before → the NEXT
            // deselect/reselect cycle resurrects it again, because
            // `softDeleteLive()` only ever touches LIVE rows and never
            // reaches an already-trashed one to correct its cause.
            $row->deleted_by_deselection = false;

            // `restore()` saves every dirty attribute in the SAME UPDATE,
            // including the position reassignment and the flag clear above.
            $row->restore();
            $restored++;
        }
    }

    /**
     * Copies the catalogue defaults for this competency as a frozen
     * snapshot (D9/D10 — never a reference), from the revision the
     * project's OWN FrameworkVersion is pinned to, capped by the platform's
     * per-assessment-type ceiling, `operator_modified = false` (the DB
     * column default — never written explicitly, since `ApplyCompetencySelection`
     * is the one write path that must not claim these rows as operator-authored).
     *
     * Falls back to the latest PUBLISHED revision's defaults (matched by
     * competency CODE — see `latestPublishedDefaults()`) whenever the pinned
     * revision has none, for BOTH callers alike: `apply()`'s fresh-selection
     * path and `ensureCompetencyHasQuestions()`'s backfill path. Every
     * pre-existing `FrameworkVersion` is pinned to the baseline, which is
     * never authored with defaults, so the pin alone cannot auto-fill
     * either one (R3-autofill-inert-for-baseline-pins).
     */
    private function copyDefaults(Project $project, int $competencyId): void
    {
        $revisionId = $project->frameworkVersion?->revision_id;

        if ($revisionId === null) {
            // No pinned revision to copy from. `FrameworkVersion::booted()`'s
            // `creating` listener (G2, PR3) resolves this on every ordinary
            // creation path once a published revision exists; a null pin
            // here means none did yet, and there is nothing to copy — not a
            // rule this action enforces.
            return;
        }

        $defaults = FrameworkDefaultQuestion::where('revision_id', $revisionId)
            ->where('competency_id', $competencyId)
            ->orderBy('position')
            ->get();

        if ($defaults->isEmpty()) {
            $defaults = $this->latestPublishedDefaults($competencyId, $revisionId);
        }

        if ($defaults->isEmpty()) {
            // Zero catalogue defaults is not an error (project-config spec —
            // "A competency with no catalogue defaults yields zero rows").
            return;
        }

        $cap = $this->platformSettings->maxQuestionsPerCompetency((string) $project->assessment_type);

        foreach ($defaults->take($cap)->values() as $position => $default) {
            ProjectQuestion::create([
                'project_id' => $project->id,
                'competency_id' => $competencyId,
                'text' => $default->getTranslations('text'),
                'position' => $position,
            ]);
        }
    }

    /**
     * FALLBACK for a pinned revision with no defaults. Every pre-existing
     * project is pinned to the BASELINE, whose defaults are always empty
     * (the seeder never writes them; catalogue CRUD only ever lands in a
     * NEW published revision) — so the pin alone can never recover, on a
     * fresh selection or a backfill alike. Matched by CODE, not id:
     * competency rows are revision-scoped (a full clone per draft/publish,
     * D1), so the pinned competency's own id never appears elsewhere.
     *
     * @return Collection<int, FrameworkDefaultQuestion>
     */
    private function latestPublishedDefaults(int $competencyId, int $pinnedRevisionId): Collection
    {
        $latestRevisionId = FrameworkCatalogRevision::latestPublished()?->id;

        if ($latestRevisionId === null || $latestRevisionId === $pinnedRevisionId) {
            return new Collection;
        }

        $code = Competency::where('id', $competencyId)->value('code');
        $latestCompetencyId = $code === null
            ? null
            : Competency::where('revision_id', $latestRevisionId)->where('code', $code)->value('id');

        if ($latestCompetencyId === null) {
            return new Collection;
        }

        return FrameworkDefaultQuestion::where('revision_id', $latestRevisionId)
            ->where('competency_id', $latestCompetencyId)
            ->orderBy('position')
            ->get();
    }
}
