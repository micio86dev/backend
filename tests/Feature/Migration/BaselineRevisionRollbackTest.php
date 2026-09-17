<?php

declare(strict_types=1);

/**
 * RED/GREEN — 4.1 (framework-catalogue-authoring PR 1): `down()` restores a
 * SINGLE-revision schema, an explicit, tested limitation rather than an
 * assumption (design.md D1 — "where beta is taken advantage of").
 *
 * Reverting after a SECOND revision exists is a reseed, not a rollback: this
 * test only documents and proves the single-revision case, which is the only
 * case this PR's migrations are ever run against. Legitimate ONLY because the
 * product is in beta.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// Duplicated locally rather than reused from BaselineRevisionMigrationTest.php
// — Pest test files are not guaranteed a load order, and a PHP top-level
// `const` from another file is not safe to depend on here.
const ROLLBACK_TEST_REVISION_MIGRATIONS_BOUNDARY = '2026_09_15_090000_create_framework_catalog_revisions_table';

/**
 * Computed, not hard-coded (R3-003 — see the identical fix and rationale in
 * `BaselineRevisionMigrationTest.php`): the number of migrations at or after
 * the boundary, whatever that happens to be today.
 */
function rollbackTestStepsToRollBack(): int
{
    return DB::table('migrations')
        ->where('migration', '>=', ROLLBACK_TEST_REVISION_MIGRATIONS_BOUNDARY)
        ->count();
}

test('rolling back every migration at or after the revision boundary restores the pre-revision shape with baseline rows intact', function (): void {
    // Capture a baseline row's content BEFORE rollback, to prove `down()`
    // never touches catalogue content — only the revision_id column and the
    // revisions infrastructure.
    $roleCountBefore = DB::table('framework_roles')->count();
    $competencyCountBefore = DB::table('framework_competencies')->count();

    expect(Schema::hasColumn('framework_roles', 'revision_id'))->toBeTrue();
    expect(Schema::hasColumn('framework_competencies', 'revision_id'))->toBeTrue();
    expect(Schema::hasColumn('framework_bars_indicators', 'revision_id'))->toBeTrue();
    expect(Schema::hasColumn('framework_role_competency', 'revision_id'))->toBeTrue();
    expect(Schema::hasColumn('framework_versions', 'revision_id'))->toBeTrue();
    expect(Schema::hasTable('framework_catalog_revisions'))->toBeTrue();
    expect(Schema::hasTable('framework_default_questions'))->toBeTrue();

    Artisan::call('migrate:rollback', ['--step' => rollbackTestStepsToRollBack(), '--force' => true]);

    expect(Schema::hasColumn('framework_roles', 'revision_id'))->toBeFalse();
    expect(Schema::hasColumn('framework_competencies', 'revision_id'))->toBeFalse();
    expect(Schema::hasColumn('framework_bars_indicators', 'revision_id'))->toBeFalse();
    expect(Schema::hasColumn('framework_role_competency', 'revision_id'))->toBeFalse();
    expect(Schema::hasColumn('framework_versions', 'revision_id'))->toBeFalse();
    expect(Schema::hasTable('framework_catalog_revisions'))->toBeFalse();
    expect(Schema::hasTable('framework_default_questions'))->toBeFalse();

    // Catalogue CONTENT survives — only the revision infrastructure is gone.
    expect(DB::table('framework_roles')->count())->toBe($roleCountBefore);
    expect(DB::table('framework_competencies')->count())->toBe($competencyCountBefore);

    Artisan::call('migrate', ['--force' => true]);
});

test('reverting after a second revision exists is a reseed, not a rollback — documented, not silently true', function (): void {
    // A second revision (any row with is_baseline = false) makes down() an
    // unsafe operation. `code` is unique PER REVISION now
    // (`UNIQUE(revision_id, code)`, this PR's own migration) precisely so a
    // draft can clone the baseline's "ICO" role without colliding with it.
    // down() restores the PRE-revision shape — a GLOBAL `UNIQUE(code)` — and
    // two revisions both legitimately holding a role coded "ICO" is exactly
    // the shape draft-cloning produces (D1: "drafts are opened by cloning
    // the latest published revision"). This limitation is a beta-only trade
    // explicitly recorded by design.md D1 — proven here rather than merely
    // asserted in a comment.
    //
    // `draft` — the baseline is `published`, so this is the realistic PR 3
    // shape: a draft cloned from the published baseline, still open.
    $secondRevisionId = FrameworkCatalogRevision::factory()->draft()->create()->id;

    $role = Role::factory()->create(['code' => 'DUPLICATE_CODE']);
    Role::factory()->create(['code' => 'DUPLICATE_CODE', 'revision_id' => $secondRevisionId]);

    expect($role->code)->toBe('DUPLICATE_CODE');

    // Rolling back now — an unsafe operation given the duplicate code above
    // — throws rather than silently corrupting data: restoring the GLOBAL
    // UNIQUE(code) on framework_roles (migration 2026_09_15_090001's own
    // down()) cannot hold two "DUPLICATE_CODE" rows at once.
    $stepsToRollBack = rollbackTestStepsToRollBack();

    assertPostgresConstraintViolation(
        fn () => Artisan::call('migrate:rollback', ['--step' => $stepsToRollBack, '--force' => true]),
        sqlstate: '23505', // unique_violation
        constraintName: 'framework_roles_code_unique',
    );
});
