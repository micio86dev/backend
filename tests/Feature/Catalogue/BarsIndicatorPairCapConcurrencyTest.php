<?php

declare(strict_types=1);

/**
 * H6 concurrency proof (framework-catalogue-authoring PR3b, gga review
 * finding — blocking): the pair-cap trigger's own `SELECT count(*)` under
 * READ COMMITTED sees only committed rows plus its OWN — two concurrent
 * inserts against a pair holding 2 rows each count 2 + 1 = 3, neither
 * exceeds the cap, and both commit, landing 4. `pg_advisory_xact_lock()`,
 * keyed by the exact (revision, role, competency) group, closes it: the
 * second writer blocks until the first resolves, then re-counts against the
 * now-current row set.
 *
 * Proven with a genuinely separate OS process (see
 * `tests/Helpers/CatalogueRevisionRaceActor.php`) holding an uncommitted 3rd
 * indicator insert open while our own competing 4th-indicator insert races
 * it for real — the only way to reproduce the exact interleaving a single
 * PHP thread cannot produce on its own. The fixture (a non-baseline draft
 * with one role, one competency and 2 seed indicators) is created by a
 * SEPARATE, committed connection too — anything created inside THIS test's
 * own wrapped transaction would be invisible to the actor's genuinely
 * different Postgres backend session.
 */

use Illuminate\Support\Facades\DB;

test('a competing 4th-indicator insert for the same pair is refused after the actor commits its 3rd', function (): void {
    $setup = startCatalogueRaceActor('create-indicator-pair-fixture', '0');
    $createdLine = readCatalogueRaceActorLine($setup);
    stopCatalogueRaceActor($setup);
    expect($createdLine)->toStartWith('CREATED:');
    [$revisionId, $roleId, $competencyId] = array_map('intval', explode(',', substr($createdLine, strlen('CREATED:'))));

    $actor = startCatalogueRaceActor('hold-indicator-insert', "{$revisionId},{$roleId},{$competencyId},2");

    try {
        $readyLine = readCatalogueRaceActorLine($actor);
        expect($readyLine)->toBe('READY');

        assertPostgresConstraintViolation(
            fn () => DB::transaction(fn () => DB::table('framework_bars_indicators')->insert([
                'revision_id' => $revisionId, 'role_id' => $roleId, 'competency_id' => $competencyId,
                'text' => json_encode(['en' => 'main']), 'anchor_5' => json_encode(['en' => 'a5']),
                'anchor_3' => json_encode(['en' => 'a3']), 'anchor_1' => json_encode(['en' => 'a1']),
                'position' => 3, 'created_at' => now(), 'updated_at' => now(),
            ])),
            '23514',
            'framework_bars_indicators_role_pair_cap',
        );

        $doneLine = readCatalogueRaceActorLine($actor);
        expect($doneLine)->toStartWith('DONE:');
        $contentionObserved = $doneLine === 'DONE:1';

        expect($contentionObserved)->toBeTrue(
            'the actor process never observed our INSERT waiting on its advisory lock — the race was not actually exercised'
        );

        // Exactly 3 survive — the two seeds plus the actor's own 3rd. Our
        // own competing 4th was refused and rolled back.
        expect(DB::table('framework_bars_indicators')
            ->where('revision_id', $revisionId)->where('role_id', $roleId)->where('competency_id', $competencyId)
            ->count())->toBe(3);
    } finally {
        stopCatalogueRaceActor($actor);

        $cleanup = startCatalogueRaceActor('delete-indicator-pair-fixture', (string) $revisionId);
        readCatalogueRaceActorLine($cleanup);
        stopCatalogueRaceActor($cleanup);
    }
});
