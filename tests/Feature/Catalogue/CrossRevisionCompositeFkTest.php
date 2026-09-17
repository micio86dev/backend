<?php

declare(strict_types=1);

/**
 * RED/GREEN — 4.2 (framework-catalogue-authoring PR 1): a
 * `framework_bars_indicators` row whose `role_id` belongs to revision 1 and
 * whose `revision_id` says 2 is refused by PostgreSQL (composite FK), not by
 * application code. See design.md D1 — "A BARS row whose role_id belongs to
 * revision 1 and whose revision_id says 2 is refused by PostgreSQL, not by
 * review."
 */

use App\Models\Competency;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

test('a BARS indicator whose role_id belongs to one revision and whose revision_id names another is refused by the database', function (): void {
    // Created DRAFT: the content-immutability trigger (framework-catalogue-
    // authoring PR3) refuses an INSERT into a published, non-baseline
    // revision's content — this test's subject is the composite FK, which
    // is orthogonal to revision state.
    $revision2Id = DB::table('framework_catalog_revisions')->insertGetId([
        'state' => 'draft',
        'is_baseline' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $role = Role::factory()->create(); // lands in the default (baseline) revision
    $competency = Competency::factory()->create(['revision_id' => $revision2Id]);

    // role_id belongs to the baseline; revision_id names revision2 — the
    // composite FK on (role_id, revision_id) is the one that must fire, not
    // the (competency_id, revision_id) one, which is satisfied here.
    assertPostgresConstraintViolation(
        fn () => DB::table('framework_bars_indicators')->insert([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'revision_id' => $revision2Id,
            'text' => json_encode(['en' => 'x']),
            'anchor_5' => json_encode(['en' => 'x']),
            'anchor_3' => json_encode(['en' => 'x']),
            'anchor_1' => json_encode(['en' => 'x']),
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        sqlstate: '23503', // foreign_key_violation
        constraintName: 'framework_bars_indicators_role_revision_fk',
    );
});

test('a role_competency pivot row mixing revisions across role_id and competency_id is refused by the database', function (): void {
    // Draft — see the identical note on the sibling test above.
    $revision2Id = DB::table('framework_catalog_revisions')->insertGetId([
        'state' => 'draft',
        'is_baseline' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $role = Role::factory()->create(); // baseline revision
    $competency = Competency::factory()->create(['revision_id' => $revision2Id]);

    assertPostgresConstraintViolation(
        fn () => DB::table('framework_role_competency')->insert([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'revision_id' => $revision2Id,
            'position' => 0,
        ]),
        sqlstate: '23503', // foreign_key_violation
        constraintName: 'framework_role_competency_role_revision_fk',
    );
});
