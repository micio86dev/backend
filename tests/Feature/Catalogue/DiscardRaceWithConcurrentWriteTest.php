<?php

declare(strict_types=1);

/**
 * K3 (framework-catalogue-authoring PR4b, R1-001, R4-discard-race-window —
 * widens and CLOSES H12): `DiscardUnusedDraftRevision`'s own docblock used
 * to disclose a residual race window — a content write's INSERT and the
 * SEPARATE `content_version` bump statement it triggers were two
 * independently-committed statements under Postgres autocommit, and a
 * concurrent `discard()` could read `content_version = 0` and delete the
 * draft in the gap between them, discarding real, already-committed work.
 *
 * Closed by `BumpsRevisionContentVersion::withRevisionLockedForWrite()`:
 * every catalogue-content write now locks the OWNING draft revision row
 * FIRST, in the SAME transaction as the write and its bump — the identical
 * `SELECT ... FOR UPDATE` `DiscardUnusedDraftRevision::discard()` and
 * `PublishRevision::publish()` already take on that row.
 *
 * Proven with a genuinely separate OS process (see
 * `tests/Helpers/CatalogueRevisionRaceActor.php`), the same shape H3/H4/H6
 * use — the actor reproduces the FIXED write path's exact DB-level
 * behaviour (lock, insert, bump, commit, all one transaction) via raw SQL,
 * matching how H6's own actor reproduces its trigger's contention scenario
 * without going through Eloquent. K8's own write-side proof (a store()
 * racing a PUBLISH, over real HTTP, asserting the 409 body) lives here too
 * — it is the SAME lock, the SAME re-check, and the SAME fix.
 */

use App\Actions\Catalogue\DiscardUnusedDraftRevision;
use App\Actions\Catalogue\OpenDraftRevision;
use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a discard call that unblocks after a concurrent locked write already committed never discards the written content', function (): void {
    // A bare draft row, committed by a SEPARATE connection (mirrors
    // `PublishRevisionConcurrentPublishTest`'s own setup) so the actor
    // process — a genuinely different Postgres backend — can see and lock
    // it. Our own test transaction (RefreshDatabase) would otherwise hold
    // an UNCOMMITTED row `OpenDraftRevision::open()` created, invisible to
    // any other connection.
    $setup = startCatalogueRaceActor('create-committed-row', '0');
    $createdLine = readCatalogueRaceActorLine($setup);
    stopCatalogueRaceActor($setup);
    expect($createdLine)->toStartWith('CREATED:');
    $draftId = (int) substr($createdLine, strlen('CREATED:'));

    $actor = startCatalogueRaceActor('hold-locked-content-write', (string) $draftId);

    try {
        $readyLine = readCatalogueRaceActorLine($actor);
        expect($readyLine)->toStartWith('READY:');
        $competencyId = (int) substr($readyLine, strlen('READY:'));

        // The actor holds `SELECT ... FOR UPDATE` on the draft row — our
        // own `discard()` call genuinely blocks here until the actor
        // commits with `content_version` already bumped.
        app(DiscardUnusedDraftRevision::class)->discard($draftId);

        $doneLine = readCatalogueRaceActorLine($actor);
        expect($doneLine)->toStartWith('DONE:');

        expect($doneLine)->toBe(
            'DONE:1',
            'the actor never observed discard() waiting on its held lock — the race was not actually exercised'
        );

        // The draft and the actor's committed content both survive — the
        // exact outcome the pre-fix race lost.
        expect(FrameworkCatalogRevision::find($draftId))->not->toBeNull();
        expect(Competency::where('id', $competencyId)->where('code', 'K3RACEWRITE')->exists())->toBeTrue();
    } finally {
        stopCatalogueRaceActor($actor);

        // Deferred past RefreshDatabase's own rollback (see
        // `PublishRevisionConcurrentPublishTest`'s own file docblock for
        // why): `discard()`'s row lock survives until OUR enclosing
        // transaction resolves, so a separate connection's cleanup DELETE
        // run synchronously here would deadlock against ourselves.
        $this->beforeApplicationDestroyed(function () use ($draftId): void {
            $cleanup = startCatalogueRaceActor('delete-indicator-pair-fixture', (string) $draftId);
            readCatalogueRaceActorLine($cleanup);
            stopCatalogueRaceActor($cleanup);
        });
    }
});

test('K8: a store() request over HTTP refuses cleanly with 409 when the revision was published while it waited for the lock', function (): void {
    $setup = startCatalogueRaceActor('create-committed-row', '0');
    $createdLine = readCatalogueRaceActorLine($setup);
    stopCatalogueRaceActor($setup);
    expect($createdLine)->toStartWith('CREATED:');
    $draftId = (int) substr($createdLine, strlen('CREATED:'));

    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    $actor = startCatalogueRaceActor('lock-revision-row', (string) $draftId);

    try {
        $lockedLine = readCatalogueRaceActorLine($actor);
        expect($lockedLine)->toBe('LOCKED');

        // `StoreRoleRequest::rules()` resolves the draft with a PLAIN read
        // (`FrameworkCatalogRevision::openDraft()`), unaffected by the
        // actor's `FOR UPDATE` lock, and passes validation normally. The
        // controller's own `Role::withRevisionLockedForWrite()` call is
        // what genuinely blocks here — waiting on the actor's held lock —
        // until the actor flips the row to `published` and commits.
        $response = $this->withToken($token)->postJson('/api/catalogue/roles', [
            'code' => 'K8PUBLISHRACE',
            'name' => ['en' => 'x', 'it' => 'x'],
        ]);

        $doneLine = readCatalogueRaceActorLine($actor);
        expect($doneLine)->toStartWith('DONE:');
        expect($doneLine)->toBe(
            'DONE:1',
            'the actor never observed our write waiting on its held lock — the race was not actually exercised'
        );

        $response->assertStatus(409)->assertJsonPath('error', 'revision_published_during_write');
        expect(DB::table('framework_roles')->where('code', 'K8PUBLISHRACE')->exists())->toBeFalse();
    } finally {
        stopCatalogueRaceActor($actor);

        $this->beforeApplicationDestroyed(function () use ($draftId): void {
            $cleanup = startCatalogueRaceActor('delete-committed-row', (string) $draftId);
            readCatalogueRaceActorLine($cleanup);
            stopCatalogueRaceActor($cleanup);
        });
    }
});

test('a write against a revision discarded out from under it refuses with the same typed exception', function (): void {
    $draft = app(OpenDraftRevision::class)->open();

    app(DiscardUnusedDraftRevision::class)->discard($draft->id);
    expect(FrameworkCatalogRevision::find($draft->id))->toBeNull();

    expect(fn () => Role::withRevisionLockedForWrite(
        $draft->id,
        fn (): Role => Role::create(['revision_id' => $draft->id, 'code' => 'NEVERWRITTEN', 'name' => ['en' => 'x'], 'responsibilities' => ['en' => 'x']]),
    ))->toThrow(RevisionPublishedDuringWriteException::class);

    expect(Role::where('code', 'NEVERWRITTEN')->exists())->toBeFalse();
});

/**
 * Non-actor, single-process proof that EVERY one of the 12 catalogue-write
 * actions (4 controllers x store/update/destroy) issues the `FOR UPDATE`
 * lock query `withRevisionLockedForWrite()` is documented to take — gga
 * review finding: a lock-count check on `RoleController::store()` alone
 * does not prove the other 11 actions are wired the same way; removing the
 * wrapper from any of them would leave this suite green otherwise.
 */
function k3LockQueryCount(callable $request): int
{
    $count = 0;

    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'framework_catalog_revisions')
            && str_contains(strtolower($query->sql), 'for update')) {
            $count++;
        }
    });

    $request();

    return $count;
}

test('every catalogue-write action takes the revision lock before writing', function (): void {
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    // Fixture: one role and one competency to attach a BARS indicator and a
    // default question to (for the update() cases) — plus a SEPARATE,
    // childless role/competency/indicator/question each, purely for the
    // destroy() cases, so a cascade from deleting one never interferes with
    // another action's own fixture later in the same test.
    $roleId = $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'K3LOCKALL', 'name' => ['en' => 'x', 'it' => 'x'],
    ])->assertCreated()->json('data.id');
    $competencyId = $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'K3LOCKALLC', 'type' => 'standard', 'name' => ['en' => 'x', 'it' => 'x'], 'definition' => ['en' => 'x', 'it' => 'x'],
    ])->assertCreated()->json('data.id');

    $indicatorId = $this->withToken($token)->postJson('/api/catalogue/bars-indicators', [
        'role_id' => $roleId, 'competency_id' => $competencyId, 'position' => 0,
        'text' => ['en' => 'x', 'it' => 'x'], 'anchor_5' => ['en' => 'x', 'it' => 'x'],
        'anchor_3' => ['en' => 'x', 'it' => 'x'], 'anchor_1' => ['en' => 'x', 'it' => 'x'],
    ])->assertCreated()->json('data.id');

    $questionId = $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId, 'position' => 0, 'text' => ['en' => 'x', 'it' => 'x'],
    ])->assertCreated()->json('data.id');

    $roleToDeleteId = $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'K3LOCKDELROLE', 'name' => ['en' => 'x', 'it' => 'x'],
    ])->assertCreated()->json('data.id');
    $competencyToDeleteId = $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'K3LOCKDELCOMP', 'type' => 'standard', 'name' => ['en' => 'x', 'it' => 'x'], 'definition' => ['en' => 'x', 'it' => 'x'],
    ])->assertCreated()->json('data.id');
    $indicatorToDeleteId = $this->withToken($token)->postJson('/api/catalogue/bars-indicators', [
        'role_id' => $roleId, 'competency_id' => $competencyId, 'position' => 1,
        'text' => ['en' => 'x', 'it' => 'x'], 'anchor_5' => ['en' => 'x', 'it' => 'x'],
        'anchor_3' => ['en' => 'x', 'it' => 'x'], 'anchor_1' => ['en' => 'x', 'it' => 'x'],
    ])->assertCreated()->json('data.id');
    $questionToDeleteId = $this->withToken($token)->postJson('/api/catalogue/default-questions', [
        'competency_id' => $competencyId, 'position' => 1, 'text' => ['en' => 'x', 'it' => 'x'],
    ])->assertCreated()->json('data.id');

    $actions = [
        'RoleController::update' => fn () => $this->withToken($token)->patchJson("/api/catalogue/roles/{$roleId}", ['name' => ['en' => 'y']])->assertOk(),
        'RoleController::destroy' => fn () => $this->withToken($token)->deleteJson("/api/catalogue/roles/{$roleToDeleteId}")->assertNoContent(),
        'CompetencyController::store' => fn () => $this->withToken($token)->postJson('/api/catalogue/competencies', [
            'code' => 'K3LOCKALLC3', 'type' => 'standard', 'name' => ['en' => 'x', 'it' => 'x'], 'definition' => ['en' => 'x', 'it' => 'x'],
        ])->assertCreated(),
        'CompetencyController::update' => fn () => $this->withToken($token)->patchJson("/api/catalogue/competencies/{$competencyId}", ['name' => ['en' => 'y']])->assertOk(),
        'CompetencyController::destroy' => fn () => $this->withToken($token)->deleteJson("/api/catalogue/competencies/{$competencyToDeleteId}")->assertNoContent(),
        'BarsIndicatorController::store' => fn () => $this->withToken($token)->postJson('/api/catalogue/bars-indicators', [
            'role_id' => $roleId, 'competency_id' => $competencyId, 'position' => 2,
            'text' => ['en' => 'x', 'it' => 'x'], 'anchor_5' => ['en' => 'x', 'it' => 'x'],
            'anchor_3' => ['en' => 'x', 'it' => 'x'], 'anchor_1' => ['en' => 'x', 'it' => 'x'],
        ])->assertCreated(),
        'BarsIndicatorController::update' => fn () => $this->withToken($token)->patchJson("/api/catalogue/bars-indicators/{$indicatorId}", ['position' => 0])->assertOk(),
        'BarsIndicatorController::destroy' => fn () => $this->withToken($token)->deleteJson("/api/catalogue/bars-indicators/{$indicatorToDeleteId}")->assertNoContent(),
        'DefaultQuestionController::store' => fn () => $this->withToken($token)->postJson('/api/catalogue/default-questions', [
            'competency_id' => $competencyId, 'position' => 2, 'text' => ['en' => 'x', 'it' => 'x'],
        ])->assertCreated(),
        'DefaultQuestionController::update' => fn () => $this->withToken($token)->patchJson("/api/catalogue/default-questions/{$questionId}", ['text' => ['en' => 'y', 'it' => 'y']])->assertOk(),
        'DefaultQuestionController::destroy' => fn () => $this->withToken($token)->deleteJson("/api/catalogue/default-questions/{$questionToDeleteId}")->assertNoContent(),
    ];

    foreach ($actions as $label => $action) {
        expect(k3LockQueryCount($action))->toBeGreaterThanOrEqual(1, "{$label} never issued the revision-lock query");
    }
});
