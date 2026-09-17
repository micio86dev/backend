<?php

declare(strict_types=1);

/**
 * H6 (framework-catalogue-authoring PR3b, R3-003): a DB-level cap of 3
 * indicators per `(revision_id, role_id, competency_id)`, and separately per
 * `(revision_id, competency_id)` for role-less `potential` indicators —
 * enforced by `2026_09_16_090000_add_bars_indicator_pair_cap_trigger`. The
 * existing partial unique indexes refuse a DUPLICATE position, never a
 * FOURTH distinct one; nothing else in the schema stops a `position`
 * sequence of 0/1/2/3 from accumulating four rows.
 */

use App\Models\FrameworkCatalogRevision;
use Illuminate\Support\Facades\DB;

function barsIndicatorPairCapInsert(int $revisionId, ?int $roleId, int $competencyId, int $position): void
{
    // Wrapped in its own transaction (a SAVEPOINT under RefreshDatabase's
    // own already-open test transaction): a caller that expects THIS insert
    // to be refused still needs the surrounding test transaction usable
    // afterward for a follow-up assertion — an uncaught constraint
    // violation poisons the ENTIRE enclosing transaction until rollback
    // (Postgres SQLSTATE 25P02), not merely the one failing statement.
    DB::transaction(function () use ($revisionId, $roleId, $competencyId, $position): void {
        DB::table('framework_bars_indicators')->insert([
            'revision_id' => $revisionId,
            'role_id' => $roleId,
            'competency_id' => $competencyId,
            'text' => json_encode(['en' => "text {$position}", 'it' => "testo {$position}"]),
            'anchor_5' => json_encode(['en' => 'a5', 'it' => 'a5']),
            'anchor_3' => json_encode(['en' => 'a3', 'it' => 'a3']),
            'anchor_1' => json_encode(['en' => 'a1', 'it' => 'a1']),
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

test('a 4th role-scoped indicator for the same (revision, role, competency) pair is refused', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $roleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'CAPROLE', 'name' => json_encode(['en' => 'x']),
        'responsibilities' => json_encode(['en' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'CAPCOMP', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    barsIndicatorPairCapInsert($revision->id, $roleId, $competencyId, 0);
    barsIndicatorPairCapInsert($revision->id, $roleId, $competencyId, 1);
    barsIndicatorPairCapInsert($revision->id, $roleId, $competencyId, 2);

    assertPostgresConstraintViolation(
        fn () => barsIndicatorPairCapInsert($revision->id, $roleId, $competencyId, 3),
        '23514',
        'framework_bars_indicators_role_pair_cap',
    );

    expect(DB::table('framework_bars_indicators')
        ->where('revision_id', $revision->id)->where('role_id', $roleId)->where('competency_id', $competencyId)
        ->count())->toBe(3);
});

test('a 4th role-less indicator for the same (revision, competency) is refused', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'CAPPOT', 'type' => 'potential',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    barsIndicatorPairCapInsert($revision->id, null, $competencyId, 0);
    barsIndicatorPairCapInsert($revision->id, null, $competencyId, 1);
    barsIndicatorPairCapInsert($revision->id, null, $competencyId, 2);

    assertPostgresConstraintViolation(
        fn () => barsIndicatorPairCapInsert($revision->id, null, $competencyId, 3),
        '23514',
        'framework_bars_indicators_roleless_pair_cap',
    );

    expect(DB::table('framework_bars_indicators')
        ->where('revision_id', $revision->id)->whereNull('role_id')->where('competency_id', $competencyId)
        ->count())->toBe(3);
});

test('an UPDATE that would push a pair past 3 is also refused', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $roleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'CAPROLE2', 'name' => json_encode(['en' => 'x']),
        'responsibilities' => json_encode(['en' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'CAPCOMP2', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    barsIndicatorPairCapInsert($revision->id, $roleId, $competencyId, 0);
    barsIndicatorPairCapInsert($revision->id, $roleId, $competencyId, 1);
    barsIndicatorPairCapInsert($revision->id, $roleId, $competencyId, 2);

    // A fourth row parked under an UNRELATED (role, competency) pair in the
    // SAME revision, then moved via UPDATE into the full pair above.
    $otherRoleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'CAPROLE3', 'name' => json_encode(['en' => 'x']),
        'responsibilities' => json_encode(['en' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $movingIndicatorId = DB::table('framework_bars_indicators')->insertGetId([
        'revision_id' => $revision->id, 'role_id' => $otherRoleId, 'competency_id' => $competencyId,
        'text' => json_encode(['en' => 'moving']), 'anchor_5' => json_encode(['en' => 'a5']),
        'anchor_3' => json_encode(['en' => 'a3']), 'anchor_1' => json_encode(['en' => 'a1']),
        'position' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('framework_bars_indicators')->where('id', $movingIndicatorId)
            ->update(['role_id' => $roleId, 'position' => 3])),
        '23514',
        'framework_bars_indicators_role_pair_cap',
    );

    expect(DB::table('framework_bars_indicators')->where('id', $movingIndicatorId)->value('role_id'))
        ->toBe($otherRoleId);
});
