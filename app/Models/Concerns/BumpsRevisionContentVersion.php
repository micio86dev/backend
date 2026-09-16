<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Models\FrameworkCatalogRevision;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Bumps the owning `FrameworkCatalogRevision.content_version` on every
 * Eloquent write to a catalogue-content model — `Role`, `Competency`,
 * `BarsIndicator`, `FrameworkDefaultQuestion` (framework-catalogue-authoring
 * PR3b, gga review finding on H5 — blocking, third pass; see
 * `2026_09_16_090002_add_content_version_to_framework_catalog_revisions`
 * for the full rationale).
 *
 * `DiscardUnusedDraftRevision` reads this counter to decide whether a draft
 * is genuinely untouched since `OpenDraftRevision` cloned it — cloning
 * writes via raw `DB::table()->insert()`, which fires no Eloquent event, so
 * only a REAL write through the catalogue's own CRUD surface ever bumps it.
 *
 * A plain `increment()` — one atomic `UPDATE ... SET content_version =
 * content_version + 1`, correct under concurrent callers without any
 * additional application-level locking.
 *
 * Each using model calls `bumpRevisionContentVersionListeners()` explicitly
 * from its OWN `booted()` (a trait's `saved`/`deleted` listeners must be
 * registered there, not via a competing `booted()` override on the trait
 * itself — a model that already defines `booted()` for its own guards,
 * `Role`/`Competency` among them, would otherwise silently lose one).
 *
 * SCOPED TO A DRAFT (gga review finding, blocking, fourth pass): the
 * baseline revision is `published` from creation and is DELIBERATELY
 * exempt from the content-immutability trigger (`2026_09_15_201434` — see
 * that migration's own docblock), so every pre-existing
 * `Role::factory()`/`Competency::factory()`/`BarsIndicator` write that
 * lands on it by default (PR1's own compatibility promise) still succeeds.
 * A raw `DB::table()->increment()` fires no model event, so it would
 * otherwise UPDATE that published row behind `FrameworkCatalogRevision::
 * booted()`'s own `updating` guard's back — quietly making its documented
 * "published, immutable, no exceptions" invariant false for this one
 * column. Content_version is meaningless for anything but an OPEN DRAFT
 * (`DiscardUnusedDraftRevision` never reads it for any other state), so
 * skipping the bump outside `draft` costs nothing real and keeps the
 * immutability invariant genuinely absolute, not "absolute except for
 * this one bookkeeping column".
 */
trait BumpsRevisionContentVersion
{
    protected static function bumpRevisionContentVersionListeners(): void
    {
        static::saved(static function (self $model): void {
            self::bumpRevisionContentVersion($model);
        });

        static::deleted(static function (self $model): void {
            self::bumpRevisionContentVersion($model);
        });
    }

    private static function bumpRevisionContentVersion(self $model): void
    {
        $revisionId = $model->getAttribute('revision_id');

        if ($revisionId === null) {
            return;
        }

        DB::table('framework_catalog_revisions')
            ->where('id', $revisionId)
            ->where('state', 'draft')
            ->increment('content_version');
    }

    /**
     * Run `$write` (the actual `create()`/`update()`/`delete()` call) inside
     * a transaction that first locks the OWNING draft revision row — the
     * SAME `SELECT ... FOR UPDATE` `PublishRevision::publish()` and
     * `DiscardUnusedDraftRevision::discard()` already take on this exact row
     * (framework-catalogue-authoring PR4b, K3, closing H12).
     *
     * WHY THIS CLOSES THE RACE `DiscardUnusedDraftRevision`'s own docblock
     * used to disclose as an accepted, narrow window: before this method
     * existed, a content write's own INSERT/UPDATE/DELETE and the
     * `content_version` bump it triggers (via `saved`/`deleted`, above) were
     * TWO SEPARATE, independently-committed statements — under Postgres
     * autocommit, each one its own instantaneous transaction. A concurrent
     * `discard()` call could take its lock, read `content_version = 0`, and
     * delete the draft in the WINDOW between those two commits, discarding
     * content a request had already genuinely saved. Locking the revision
     * row FIRST, in the SAME transaction as the write and its bump, makes
     * them ATOMIC as a unit from `discard()`'s own point of view: either
     * `discard()`'s `lockForUpdate()` blocks until this whole transaction
     * (write + bump) commits — after which `content_version` is already
     * non-zero — or `discard()` already holds the lock and completes its
     * own decision (delete or no-op) before this write is even attempted,
     * in which case the write below fails closed (see next paragraph)
     * rather than silently landing against a revision that no longer exists.
     *
     * ALSO closes K8 (a write racing a concurrent PUBLISH): the SAME lock
     * contends with `PublishRevision::publish()`'s own `lockForUpdate()`.
     * Once unblocked, the state re-check below throws a clean, typed 409
     * (`RevisionPublishedDuringWriteException`) for EITHER outcome — the
     * revision was published, or it was discarded out from under this
     * write — before the actual write is attempted, so the content-
     * immutability trigger's own `FOR SHARE` check
     * (`framework_catalog_refuse_published_content_write()`) is never even
     * reached by this path; it remains the backstop for a write that (by
     * omission or by bug) bypasses this application-level lock entirely.
     */
    public static function withRevisionLockedForWrite(int $revisionId, Closure $write): mixed
    {
        return DB::transaction(function () use ($revisionId, $write): mixed {
            $revision = FrameworkCatalogRevision::whereKey($revisionId)->lockForUpdate()->first();

            if ($revision === null || $revision->state !== 'draft') {
                throw new RevisionPublishedDuringWriteException(
                    "catalogue revision [{$revisionId}] is no longer an open draft — a concurrent publish or discard already completed."
                );
            }

            return $write();
        });
    }
}
