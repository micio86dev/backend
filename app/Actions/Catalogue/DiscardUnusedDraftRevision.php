<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Discard a draft revision this SAME request created SOLELY to validate
 * against, when that validation then failed (framework-catalogue-authoring
 * PR3b, gga review finding on H5 — blocking).
 *
 * `ResolvesOpenDraftRevision::openDraftRevisionId()` runs inside `rules()`,
 * BEFORE any rule is evaluated — a superadmin POSTing a malformed payload
 * still triggers `OpenDraftRevision::open()`, which CLONES ~450 rows and
 * COMMITS them before the 422 is even computed. A rejected request must not
 * leave a brand-new draft sitting in the platform-wide one-draft slot that
 * nobody asked for and nobody knows exists — the next publish sweep would
 * run against it.
 *
 * NEVER discards a draft another request is actually using (gga review
 * finding, blocking — two corrections, not one):
 *   - First pass: "I am the one who created this draft" (`wasRecentlyCreated`,
 *     checked by the caller) answers "did I create this row", not "is anyone
 *     else using it right now" — the one-draft unique index exists PRECISELY
 *     so concurrent superadmin requests converge on the SAME draft, so a
 *     second request reusing it while the first one's unrelated validation
 *     fails is the ORDINARY path under concurrent traffic, not an exotic
 *     edge case.
 *   - Second pass: comparing per-table ROW COUNTS between the draft and its
 *     parent (the first fix for the first finding) is STILL wrong — counts
 *     are invariant under `UPDATE`. A concurrent request renaming a role in
 *     the draft changes nothing a count can see.
 *
 * `content_version` (see that column's own migration and
 * `BumpsRevisionContentVersion`) is the real fix: every Eloquent
 * create/update/delete to `Role`/`Competency`/`BarsIndicator` bumps it,
 * while `OpenDraftRevision`'s own clone step writes via raw
 * `DB::table()->insert()` and fires no such event — so a freshly-cloned
 * draft starts, and stays, at `0` until a REAL write reaches it through the
 * catalogue's actual CRUD surface, by ANY caller.
 *
 * A narrow, disclosed residual window (gga review, non-blocking): a
 * concurrent request's own `Role::create()`/`update()`/`delete()` commits
 * its actual row change FIRST, then fires the `saved`/`deleted` event that
 * bumps `content_version` — two separate statements, not one atomic unit.
 * A discard landing in the microseconds between them still reads `0` and
 * proceeds, deleting a row that request just committed. This class is
 * explicitly best-effort cleanup for an ordinary validation-failure path,
 * not a strict concurrency primitive on par with H3/H4's proven,
 * lock-based invariants — closing this fully would need the write paths
 * themselves to hold the SAME revision lock `discard()` takes, which is a
 * larger, separate change than a 422-cleanup guard justifies today.
 */
final class DiscardUnusedDraftRevision
{
    /**
     * Never throws — a cleanup failure must not convert a request's clean
     * 422 into a 500 (gga review finding, blocking). Logged, not silent.
     */
    public function discard(int $revisionId): void
    {
        try {
            DB::transaction(function () use ($revisionId): void {
                $revision = FrameworkCatalogRevision::whereKey($revisionId)->lockForUpdate()->first();

                if ($revision === null || $revision->state !== 'draft' || $revision->content_version !== 0) {
                    return;
                }

                Competency::where('revision_id', $revisionId)->delete();
                Role::where('revision_id', $revisionId)->delete();

                $revision->delete();
            });
        } catch (Throwable $e) {
            Log::warning('DiscardUnusedDraftRevision: failed to discard an unused draft — leaving it in place', [
                'revision_id' => $revisionId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
