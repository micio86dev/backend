<?php

declare(strict_types=1);

/**
 * H4 (framework-catalogue-authoring PR3b, R3-001/R4/G3.4): two callers race
 * to open the FIRST draft. `OpenDraftRevision::open()`'s own initial
 * `where('state', 'draft')->first()` cannot see a concurrent caller's
 * still-uncommitted INSERT (Postgres read-committed isolation) — both
 * callers proceed to `FrameworkCatalogRevision::create()`, and the second
 * one's INSERT collides with `framework_catalog_revisions_one_draft`. Before
 * this fix that surfaced as an uncaught `QueryException` (a 500 on a
 * superadmin's first edit); the LOSER must instead continue the WINNER's
 * draft.
 *
 * Proven with a genuinely separate OS process (see
 * `tests/Helpers/CatalogueRevisionRaceActor.php`) holding an uncommitted
 * competing INSERT open while OUR OWN `open()` call attempts its own
 * conflicting INSERT — the only way to reproduce the exact interleaving a
 * single PHP thread cannot produce on its own. Contention is confirmed by
 * polling `pg_locks`, never a fixed sleep.
 */

use App\Actions\Catalogue\OpenDraftRevision;
use App\Models\FrameworkCatalogRevision;

test('the loser of the one-draft race continues the winner draft instead of throwing', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    $actor = startCatalogueRaceActor('insert-competing-draft', (string) $baseline->id);
    $winnerDraftId = null;

    try {
        $readyLine = readCatalogueRaceActorLine($actor);
        expect($readyLine)->toStartWith('READY:');
        $winnerDraftId = (int) substr($readyLine, strlen('READY:'));

        // The actor's INSERT is uncommitted at this point — our own
        // open()'s initial SELECT genuinely cannot see it (read committed
        // isolation), so this exercises the REAL race window, not a
        // pre-seeded "already exists" shortcut that would never reach the
        // fix under test at all.
        $result = app(OpenDraftRevision::class)->open();

        $doneLine = readCatalogueRaceActorLine($actor);
        expect($doneLine)->toStartWith('DONE:');
        $contentionObserved = $doneLine === 'DONE:1';

        expect($contentionObserved)->toBeTrue(
            'the actor process never observed our INSERT waiting on its uncommitted row — the race was not actually exercised'
        );

        expect($result->id)->toBe($winnerDraftId);
        expect(FrameworkCatalogRevision::where('state', 'draft')->count())->toBe(1);

        // The loser did not partially clone anything of its own — the
        // winner's draft is the only content that exists at that id.
        expect($result->parent_revision_id)->toBe($baseline->id);
    } finally {
        stopCatalogueRaceActor($actor);

        if ($winnerDraftId !== null) {
            $cleanup = startCatalogueRaceActor('delete-committed-row', (string) $winnerDraftId);
            readCatalogueRaceActorLine($cleanup);
            stopCatalogueRaceActor($cleanup);
        }
    }
});
