<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Bumps the owning `FrameworkCatalogRevision.content_version` on every
 * Eloquent write to a catalogue-content model — `Role`, `Competency`,
 * `BarsIndicator` (framework-catalogue-authoring PR3b, gga review finding
 * on H5 — blocking, third pass; see `2026_09_16_090002_add_content_version_
 * to_framework_catalog_revisions` for the full rationale).
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
}
