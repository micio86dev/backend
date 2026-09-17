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
 * create/update/delete to `Role`/`Competency`/`BarsIndicator`/
 * `FrameworkDefaultQuestion` bumps it, while `OpenDraftRevision`'s own clone
 * step writes via raw `DB::table()->insert()` and fires no such event — so
 * a freshly-cloned draft starts, and stays, at `0` until a REAL write
 * reaches it through the catalogue's actual CRUD surface, by ANY caller.
 *
 * CLOSED for the catalogue's own CRUD surface (framework-catalogue-authoring
 * PR4b, K3 — widens and closes H12, which this class's own gga-review
 * history left as a disclosed residual window): every write issued by the
 * four catalogue controllers (`RoleController`/`CompetencyController`/
 * `BarsIndicatorController`/`DefaultQuestionController`) and by
 * `catalogue:import` goes through
 * `BumpsRevisionContentVersion::withRevisionLockedForWrite()`, which locks
 * THIS SAME revision row (`SELECT ... FOR UPDATE`) BEFORE performing the
 * write, and keeps the write's own INSERT/UPDATE/DELETE and its
 * `content_version` bump inside ONE transaction. `discard()`'s own
 * `lockForUpdate()` below therefore either blocks until a concurrent
 * write's WHOLE transaction (row change + bump) commits — after which
 * `content_version` is already non-zero — or already holds the lock and
 * completes its own decision before that write is even attempted, in which
 * case the write fails closed with a clean `RevisionPublishedDuringWriteException`
 * rather than silently landing against a since-deleted revision. Proven
 * under genuine concurrency in
 * `tests/Feature/Catalogue/DiscardRaceWithConcurrentWriteTest.php`, the
 * same separate-OS-process shape H3/H4/H6 use.
 *
 * NOT CLOSED for `framework:forget-locale` (Z21, R2-discard-closed-claim-
 * overstated, REQUIRED BEFORE ARCHIVE — disclosed, not fixed):
 * `ForgetFrameworkLocaleCommand` iterates and saves EVERY `Role`/
 * `Competency`/`BarsIndicator` row on the platform, including any row that
 * belongs to the currently open draft, through a plain `->save()` — never
 * through `withRevisionLockedForWrite()`. `content_version` still bumps
 * (each model's own `saved` listener fires regardless of which caller
 * triggered the save — see `BumpsRevisionContentVersion`'s own docblock),
 * but nothing takes the row lock FIRST, so a `discard()` call that acquires
 * the lock before that command reaches a given draft's first row can still
 * delete the draft out from under it. That is not silent data loss the way
 * the CLOSED race above used to be: the command's own later `->save()` on
 * the now-gone row simply affects zero rows (Postgres does not error on
 * that), and there is nothing left to lose — the row it meant to edit is
 * already gone. Left as-is rather than widened into a per-revision lock:
 * this command is a rare, forced (`--force` required outside `local`),
 * already-destructive, ops-only rollback that already refuses to run while
 * any `FrameworkVersion` is locked, and it iterates the whole platform in
 * one query, not per-revision — retrofitting a single-revision lock into
 * that shape is a larger change than this narrow, non-corrupting residual
 * race justifies. Pinned by
 * `tests/Feature/Console/ForgetLocaleCommandTest.php` ("forget-locale writes
 * to an open draft without taking the revision lock the CRUD surface
 * takes").
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
