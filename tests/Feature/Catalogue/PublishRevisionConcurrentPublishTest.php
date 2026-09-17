<?php

declare(strict_types=1);

/**
 * H3 (framework-catalogue-authoring PR3b, R3-004/R4): `PublishRevision`
 * must re-check the locked row's STATE after `SELECT ... FOR UPDATE`, not
 * only run the sweep against it. A second publish call queued behind the
 * same lock unblocks only after the first one already flipped the row to
 * `published` — the check that was missing here would otherwise re-run the
 * (now vacuously passing) sweep and then attempt `$locked->save()`, which
 * `FrameworkCatalogRevision::booted()`'s own immutability guard refuses as
 * an uncaught exception. The fix returns a clean 422 violation instead.
 *
 * Proven with a genuinely separate OS process (see
 * `tests/Helpers/CatalogueRevisionRaceActor.php`) holding the row's
 * `SELECT ... FOR UPDATE` lock open while OUR OWN `PublishRevision::publish()`
 * call is itself blocked waiting for it — the only way to reproduce the
 * exact interleaving a single PHP thread cannot produce on its own.
 * Contention is confirmed by polling `pg_blocking_pids()`, never a fixed
 * sleep.
 *
 * Cleanup is deliberately NOT a synchronous `DELETE` inside this test's own
 * body (confirmed empirically, not assumed): our OWN `lockForUpdate()` call
 * above takes a REAL row lock that Postgres does not release when the
 * NESTED transaction/savepoint it ran in commits — a row lock survives until
 * the ENCLOSING transaction (RefreshDatabase's, open for this whole test)
 * itself resolves. A separate connection's `DELETE` on this exact row would
 * therefore deadlock against our own not-yet-finished test. `$this->
 * beforeApplicationDestroyed()` queues the cleanup FIFO, after RefreshDatabase's
 * own rollback callback (registered during `setUp()`, before this test body
 * runs) — by the time ours fires, our lock is already released.
 */

use App\Actions\Catalogue\PublishRevision;
use App\Models\FrameworkCatalogRevision;
use App\Models\User;

test('a publish call that unblocks after a concurrent publish already completed returns a clean violation, never a 500', function (): void {
    // A bare draft row, committed by a SEPARATE connection so the actor
    // process (a genuinely different Postgres backend) can see and lock it.
    // No catalogue content is needed: the fix under test short-circuits
    // BEFORE the sweep ever runs.
    $setup = startCatalogueRaceActor('create-committed-row', '0');
    $createdLine = readCatalogueRaceActorLine($setup);
    stopCatalogueRaceActor($setup);
    expect($createdLine)->toStartWith('CREATED:');
    $draftId = (int) substr($createdLine, strlen('CREATED:'));

    $actor = startCatalogueRaceActor('lock-revision-row', (string) $draftId);

    try {
        $lockedLine = readCatalogueRaceActorLine($actor);
        expect($lockedLine)->toBe('LOCKED');

        $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

        // The actor holds `SELECT ... FOR UPDATE` on this exact row — our
        // own `lockForUpdate()->firstOrFail()` inside `publish()` genuinely
        // blocks here until the actor commits with `state = 'published'`.
        $revisionArgument = FrameworkCatalogRevision::findOrFail($draftId);
        $violations = app(PublishRevision::class)->publish($revisionArgument, $user);

        $doneLine = readCatalogueRaceActorLine($actor);
        expect($doneLine)->toStartWith('DONE:');
        $contentionObserved = $doneLine === 'DONE:1';

        expect($contentionObserved)->toBeTrue(
            'the actor process never observed our lockForUpdate() waiting on its held lock — the race was not actually exercised'
        );

        expect($violations)->toHaveCount(1);
        expect($violations[0]['rule'])->toBe('revision_already_published');
        expect($violations[0]['subject'])->toBe("revision:{$draftId}");

        // Untouched by our (short-circuited) call — still exactly what the
        // actor wrote.
        expect(FrameworkCatalogRevision::findOrFail($draftId)->state)->toBe('published');
    } finally {
        stopCatalogueRaceActor($actor);

        // Deferred past RefreshDatabase's own rollback — see the file
        // docblock. Running this while our own transaction (and its
        // lockForUpdate()) is still open would deadlock against ourselves.
        $this->beforeApplicationDestroyed(function () use ($draftId): void {
            $cleanup = startCatalogueRaceActor('delete-committed-row', (string) $draftId);
            readCatalogueRaceActorLine($cleanup);
            stopCatalogueRaceActor($cleanup);
        });
    }
});
