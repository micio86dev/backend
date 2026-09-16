<?php

declare(strict_types=1);

/**
 * H2 (framework-catalogue-authoring PR3b, R3-008): `OpenDraftRevision`
 * cloning had zero test coverage. Proves, against a REALLY SEEDED baseline
 * (not a hand-built one-row fixture):
 *   - every per-table count in the clone equals the parent's;
 *   - every pivot/indicator/default-question row is remapped onto the
 *     CLONE's own new role/competency ids — none still points at a parent
 *     id (the exact correctness property `OpenDraftRevision`'s own docblock
 *     claims: an in-memory old-id -> new-id map, not a literal
 *     `INSERT ... SELECT`, because the composite FKs would refuse a cloned
 *     row still pointing at the parent's ids);
 *   - role-less MTG/LAT indicators (`potential` competencies) stay
 *     role-less in the clone;
 *   - `parent_revision_id` is set to the parent.
 */

use App\Actions\Catalogue\OpenDraftRevision;
use App\Models\FrameworkCatalogRevision;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('opening a draft clones the seeded baseline with every id remapped', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    // Exercise default-question cloning too — the seeder does not write this
    // table (PR4 scope), so a couple of rows are inserted directly against
    // the real seeded baseline's own competencies.
    $competencyForDefaults = DB::table('framework_competencies')
        ->where('revision_id', $baseline->id)
        ->orderBy('id')
        ->value('id');

    DB::table('framework_default_questions')->insert([
        [
            'revision_id' => $baseline->id, 'competency_id' => $competencyForDefaults,
            'text' => json_encode(['en' => 'Tell me about a time...', 'it' => 'Raccontami di una volta...']),
            'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'revision_id' => $baseline->id, 'competency_id' => $competencyForDefaults,
            'text' => json_encode(['en' => 'What happened next?', 'it' => 'Cosa è successo dopo?']),
            'position' => 1, 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $baselineRoleIds = DB::table('framework_roles')->where('revision_id', $baseline->id)->pluck('id');
    $baselineCompetencyIds = DB::table('framework_competencies')->where('revision_id', $baseline->id)->pluck('id');

    $parentCounts = [
        'framework_roles' => DB::table('framework_roles')->where('revision_id', $baseline->id)->count(),
        'framework_competencies' => DB::table('framework_competencies')->where('revision_id', $baseline->id)->count(),
        'framework_role_competency' => DB::table('framework_role_competency')->where('revision_id', $baseline->id)->count(),
        'framework_bars_indicators' => DB::table('framework_bars_indicators')->where('revision_id', $baseline->id)->count(),
        'framework_default_questions' => DB::table('framework_default_questions')->where('revision_id', $baseline->id)->count(),
    ];

    // Sanity: the fixture is a REAL seeded catalogue, not a hand-rolled stub.
    // 20 competency ROWS (18 standard + MTG + LAT) is distinct from the
    // "85 anchored competencies" figure in CLAUDE.md/
    // `CatalogueBaselineLiteralCountsTest`, which counts distinct
    // (role_id, competency_id) PAIRS in `framework_bars_indicators` — a
    // standard competency's CODE repeats across roles, but its `framework_
    // competencies` ROW does not.
    expect($parentCounts['framework_roles'])->toBe(5);
    expect($parentCounts['framework_competencies'])->toBe(20);
    expect($parentCounts['framework_role_competency'])->toBe(83);
    // 255 = 83 role-scoped pairs x 3 indicators, plus MTG and LAT (potential,
    // role-less) x 3 indicators each = 249 + 6.
    expect($parentCounts['framework_bars_indicators'])->toBe(255);
    expect($parentCounts['framework_default_questions'])->toBe(2);

    $draft = app(OpenDraftRevision::class)->open();

    expect($draft->state)->toBe('draft');
    expect($draft->parent_revision_id)->toBe($baseline->id);

    foreach ($parentCounts as $table => $expectedCount) {
        expect(DB::table($table)->where('revision_id', $draft->id)->count())
            ->toBe($expectedCount, "{$table} count in the clone must equal the parent's");
    }

    // No pivot row in the draft still points at a PARENT role/competency id.
    expect(
        DB::table('framework_role_competency')->where('revision_id', $draft->id)
            ->where(function ($query) use ($baselineRoleIds, $baselineCompetencyIds): void {
                $query->whereIn('role_id', $baselineRoleIds)
                    ->orWhereIn('competency_id', $baselineCompetencyIds);
            })
            ->count()
    )->toBe(0);

    // No BARS indicator row in the draft still points at a PARENT role/
    // competency id (role-scoped rows only — role-less rows are asserted
    // separately below).
    expect(
        DB::table('framework_bars_indicators')->where('revision_id', $draft->id)
            ->whereNotNull('role_id')
            ->where(function ($query) use ($baselineRoleIds, $baselineCompetencyIds): void {
                $query->whereIn('role_id', $baselineRoleIds)
                    ->orWhereIn('competency_id', $baselineCompetencyIds);
            })
            ->count()
    )->toBe(0);

    expect(
        DB::table('framework_bars_indicators')->where('revision_id', $draft->id)
            ->whereNull('role_id')
            ->whereIn('competency_id', $baselineCompetencyIds)
            ->count()
    )->toBe(0);

    // No default-question row in the draft still points at a PARENT
    // competency id.
    expect(
        DB::table('framework_default_questions')->where('revision_id', $draft->id)
            ->whereIn('competency_id', $baselineCompetencyIds)
            ->count()
    )->toBe(0);

    // Role-less MTG/LAT indicators (potential competencies) stay role-less
    // in the clone — the SAME count of role-less rows survives the clone.
    $parentRoleLessCount = DB::table('framework_bars_indicators')
        ->where('revision_id', $baseline->id)->whereNull('role_id')->count();
    $draftRoleLessCount = DB::table('framework_bars_indicators')
        ->where('revision_id', $draft->id)->whereNull('role_id')->count();

    expect($parentRoleLessCount)->toBeGreaterThan(0);
    expect($draftRoleLessCount)->toBe($parentRoleLessCount);

    // Every clone row genuinely resolves to CLONE-owned roles/competencies —
    // the composite FK already enforces this at the DB layer; this asserts
    // the application-visible consequence explicitly.
    $draftRoleIds = DB::table('framework_roles')->where('revision_id', $draft->id)->pluck('id');
    $draftCompetencyIds = DB::table('framework_competencies')->where('revision_id', $draft->id)->pluck('id');

    $pivotRoleIdsUsed = DB::table('framework_role_competency')->where('revision_id', $draft->id)->pluck('role_id')->unique();
    $pivotCompetencyIdsUsed = DB::table('framework_role_competency')->where('revision_id', $draft->id)->pluck('competency_id')->unique();

    expect($pivotRoleIdsUsed->diff($draftRoleIds)->isEmpty())->toBeTrue();
    expect($pivotCompetencyIdsUsed->diff($draftCompetencyIds)->isEmpty())->toBeTrue();
});
