<?php

declare(strict_types=1);

/**
 * Z4 (R3-validate-then-lock-500, REQUIRED BEFORE ARCHIVE) — the HTTP-level
 * proof `BarsIndicatorPairCapConcurrencyTest` does not cover: that test
 * proves the DB layer's own backstop (H6's pair-cap trigger) correctly
 * refuses a losing concurrent insert with SQLSTATE 23514, via a raw
 * `DB::table()->insert()`. It does NOT go through the real HTTP endpoint, so
 * it cannot show what the CALLER actually receives.
 *
 * This test reuses the SAME race-actor fixture (a draft with one role, one
 * competency and 2 seed indicators) and the SAME contended-insert
 * synchronization, but issues the competing 3rd indicator through the real
 * `POST /api/catalogue/bars-indicators` endpoint. `StoreBarsIndicatorRequest`'s
 * own FormRequest pre-check sees only the 2 COMMITTED seed rows (the actor's
 * own 3rd is uncommitted at that point) and passes — the write itself is
 * what collides with the trigger. Before Z4, that collision propagated as an
 * uncaught `QueryException` → HTTP 500; the fix maps it to the SAME 422 shape
 * a sequential, FormRequest-caught 4th-indicator refusal already returns.
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('Z4: a losing concurrent HTTP store request gets 422, never 500', function (): void {
    $setup = startCatalogueRaceActor('create-indicator-pair-fixture', '0');
    $createdLine = readCatalogueRaceActorLine($setup);
    stopCatalogueRaceActor($setup);
    expect($createdLine)->toStartWith('CREATED:');
    [$revisionId, $roleId, $competencyId] = array_map('intval', explode(',', substr($createdLine, strlen('CREATED:'))));

    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    $actor = startCatalogueRaceActor('hold-indicator-insert', "{$revisionId},{$roleId},{$competencyId},2");

    try {
        $readyLine = readCatalogueRaceActorLine($actor);
        expect($readyLine)->toBe('READY');

        // Our own FormRequest pre-check sees only the 2 COMMITTED seed rows
        // (the actor's own 3rd is uncommitted) — it passes, exactly the race
        // window Z4 closes. The write itself is what collides.
        $response = $this->withToken($token)->postJson('/api/catalogue/bars-indicators', [
            'role_id' => $roleId,
            'competency_id' => $competencyId,
            'position' => 3,
            'text' => ['en' => 'main'],
            'anchor_5' => ['en' => 'a5'],
            'anchor_3' => ['en' => 'a3'],
            'anchor_1' => ['en' => 'a1'],
        ]);

        $doneLine = readCatalogueRaceActorLine($actor);
        expect($doneLine)->toStartWith('DONE:');
        $contentionObserved = $doneLine === 'DONE:1';

        expect($contentionObserved)->toBeTrue(
            'the actor process never observed our INSERT waiting on its advisory lock — the race was not actually exercised'
        );

        $response->assertStatus(422);
        expect($response->json('error'))->toBe('bars_indicator_pair_full');

        // Exactly 3 survive — the two seeds plus the actor's own 3rd.
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
