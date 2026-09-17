<?php

declare(strict_types=1);

/**
 * Z5 (R4-import-bypasses-revision-lock, REQUIRED BEFORE ARCHIVE):
 * `catalogue:import` used to write through plain `save()`/`sync()` calls
 * with no lock at all — the one catalogue-content writer that did not take
 * `BumpsRevisionContentVersion::withRevisionLockedForWrite()`'s `SELECT ...
 * FOR UPDATE` on the draft revision row every CRUD controller already takes.
 * A concurrent `PublishRevision`/`DiscardUnusedDraftRevision` could flip or
 * delete the row while the import was mid-write, uncontended.
 *
 * Proven with a genuinely separate OS process (see
 * `tests/Helpers/CatalogueRevisionRaceActor.php`) holding the SAME row's
 * `SELECT ... FOR UPDATE` lock open while `catalogue:import` is itself
 * blocked waiting for it — the only way to reproduce the exact interleaving
 * a single PHP thread cannot produce on its own.
 */

use Illuminate\Support\Facades\Artisan;

function catalogueImportLockTestSourceTree(): string
{
    $dir = sys_get_temp_dir().'/catalogue-import-lock-'.uniqid('', true);
    mkdir("{$dir}/bars", recursive: true);

    file_put_contents("{$dir}/roles.json", json_encode([], JSON_THROW_ON_ERROR));
    file_put_contents("{$dir}/competencies.json", json_encode([
        'ZLOCK' => ['name' => ['en' => 'x'], 'definition' => ['en' => 'x']],
    ], JSON_THROW_ON_ERROR));

    return $dir;
}

test('Z5: import blocks on a concurrently locked revision row and fails closed once it unblocks published', function (): void {
    // A bare draft row, committed by a SEPARATE connection so both the actor
    // and `OpenDraftRevision::open()` (called from inside `catalogue:import`)
    // see and resolve the SAME row as "the" open draft.
    $setup = startCatalogueRaceActor('create-committed-row', '0');
    $createdLine = readCatalogueRaceActorLine($setup);
    stopCatalogueRaceActor($setup);
    expect($createdLine)->toStartWith('CREATED:');
    $draftId = (int) substr($createdLine, strlen('CREATED:'));

    $actor = startCatalogueRaceActor('lock-revision-row', (string) $draftId);

    try {
        $lockedLine = readCatalogueRaceActorLine($actor);
        expect($lockedLine)->toBe('LOCKED');

        $dir = catalogueImportLockTestSourceTree();

        // The actor holds `SELECT ... FOR UPDATE` on this exact row —
        // `catalogue:import`'s own lock (Z5's fix) genuinely blocks here
        // until the actor commits with `state = 'published'`.
        $exitCode = Artisan::call('catalogue:import', ['--into-draft' => true, '--path' => $dir]);

        $doneLine = readCatalogueRaceActorLine($actor);
        expect($doneLine)->toStartWith('DONE:');
        $contentionObserved = $doneLine === 'DONE:1';

        expect($contentionObserved)->toBeTrue(
            'the actor process never observed catalogue:import waiting on its held lock — the race was not actually exercised, so this proves nothing about Z5'
        );

        // The lock unblocked onto an already-published row — the import
        // fails closed (never writes content into it) instead of racing the
        // publish. Non-zero exit, not an uncaught exception / crash.
        expect($exitCode)->not->toBe(0);
        expect(DB::table('framework_competencies')->where('revision_id', $draftId)->where('code', 'ZLOCK')->exists())->toBeFalse();
    } finally {
        stopCatalogueRaceActor($actor);

        // Deferred past RefreshDatabase's own rollback — see
        // `PublishRevisionConcurrentPublishTest`'s identical note: our own
        // blocked lock attempt still holds a real row lock until the
        // ENCLOSING (RefreshDatabase) transaction resolves.
        $this->beforeApplicationDestroyed(function () use ($draftId): void {
            $cleanup = startCatalogueRaceActor('delete-committed-row', (string) $draftId);
            readCatalogueRaceActorLine($cleanup);
            stopCatalogueRaceActor($cleanup);
        });
    }
});
