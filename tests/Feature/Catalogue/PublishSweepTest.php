<?php

declare(strict_types=1);

/**
 * RED/GREEN — 10.1/11.3 (framework-catalogue-authoring PR3, D3): the
 * blocking publish sweep. A pair with 2 indicators, an unanchored
 * competency, a `potential` competency present in the pivot, and a
 * role-scoped MTG/LAT indicator — each refuses publish (422 naming every
 * violation) and leaves `state = 'draft'`.
 *
 * The original ">3" half of this fixture (a pair deliberately given 4
 * indicators) is REMOVED (framework-catalogue-authoring PR3b, H6): a DB-level
 * trigger (`2026_09_16_090000_add_bars_indicator_pair_cap_trigger`) now
 * refuses a 4th indicator for any `(revision, role, competency)` pair
 * outright, so that state can no longer be constructed at all, by this test
 * or by any other writer — `indicatorCountViolations()`'s own `!= 3` check
 * stays correct and still catches the "< 3" direction below; the "> 3"
 * direction is unreachable rather than untested.
 */

use App\Actions\Catalogue\PublishRevision;
use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function publishSweepIndicator(int $revisionId, ?int $roleId, int $competencyId, int $position): void
{
    DB::table('framework_bars_indicators')->insert([
        'revision_id' => $revisionId,
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'text' => json_encode(['en' => "text {$position}"]),
        'anchor_5' => json_encode(['en' => "anchor5 {$position}"]),
        'anchor_3' => json_encode(['en' => "anchor3 {$position}"]),
        'anchor_1' => json_encode(['en' => "anchor1 {$position}"]),
        'position' => $position,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A minimal, fully-manufactured draft — deliberately NOT cloned from the
 * real baseline — with one violation of each kind the sweep must catch.
 *
 * @return array{revision: FrameworkCatalogRevision, roleId: int, twoIndicatorCompetencyId: int, emptyCompetencyId: int, okCompetencyId: int, potentialCompetencyId: int}
 */
function publishSweepFixture(): array
{
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $roleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'SWROLE',
        'name' => json_encode(['en' => 'Sweep Role']),
        'responsibilities' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $mk = fn (string $code, string $type = 'standard'): int => DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => $code, 'type' => $type,
        'name' => json_encode(['en' => $code]), 'definition' => json_encode(['en' => $code]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $okId = $mk('SWOK');
    $twoId = $mk('SWTWO');
    $emptyId = $mk('SWEMPTY');
    $potentialId = $mk('MTG_SW', 'potential');

    foreach ([$okId, $twoId, $emptyId] as $position => $competencyId) {
        DB::table('framework_role_competency')->insert([
            'revision_id' => $revision->id, 'role_id' => $roleId, 'competency_id' => $competencyId, 'position' => $position,
        ]);
    }

    // OK: exactly 3.
    for ($i = 0; $i < 3; $i++) {
        publishSweepIndicator($revision->id, $roleId, $okId, $i);
    }
    // Violation: only 2 — the DB-level cap (H6) only refuses a 4th; fewer
    // than 3 is a publish-sweep violation, not a write-time refusal.
    for ($i = 0; $i < 2; $i++) {
        publishSweepIndicator($revision->id, $roleId, $twoId, $i);
    }
    // Violation: $emptyId has zero indicators (declared pair, unanchored).

    // Violation: `potential` competency present in the pivot.
    DB::table('framework_role_competency')->insert([
        'revision_id' => $revision->id, 'role_id' => $roleId, 'competency_id' => $potentialId, 'position' => 99,
    ]);

    // Violation: role-scoped indicator for a `potential` competency.
    publishSweepIndicator($revision->id, $roleId, $potentialId, 0);

    return [
        'revision' => $revision,
        'roleId' => $roleId,
        'twoIndicatorCompetencyId' => $twoId,
        'emptyCompetencyId' => $emptyId,
        'okCompetencyId' => $okId,
        'potentialCompetencyId' => $potentialId,
    ];
}

test('a publish sweep names every violation at once and leaves the revision draft', function (): void {
    $fixture = publishSweepFixture();
    $actor = User::factory()->create(['is_superadmin' => true, 'organization_id' => null]);

    $violations = app(PublishRevision::class)->publish($fixture['revision'], $actor);

    $rules = array_column($violations, 'rule');

    expect($rules)->toContain('exactly_three_indicators');
    expect($rules)->toContain('pair_must_be_anchored');
    expect($rules)->toContain('potential_competency_not_in_pivot');
    expect($rules)->toContain('potential_indicator_must_be_role_less');

    $subjects = array_column($violations, 'subject');
    expect($subjects)->toContain("role:{$fixture['roleId']} competency:{$fixture['twoIndicatorCompetencyId']}");

    expect($fixture['revision']->fresh()->state)->toBe('draft');
});

test('a fully correct pair contributes no violation', function (): void {
    $fixture = publishSweepFixture();

    $violations = app(PublishRevision::class)->violations($fixture['revision']->fresh());

    $subjects = array_column($violations, 'subject');
    expect($subjects)->not->toContain("role:{$fixture['roleId']} competency:{$fixture['okCompetencyId']}");
});

test('a potential competency with fewer than 3 role-less indicators refuses publish', function (): void {
    // gga review finding: indicatorCountViolations() filters whereNotNull
    // role_id and emptyPairViolations() only walks the pivot — neither
    // ever looks at a role-less (potential) competency's own indicator
    // count. Proven directly: a fresh potential competency with only 2
    // role-less indicators (never 3) must still refuse publish.
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $potentialId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'MTGSHORT', 'type' => 'potential',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    publishSweepIndicator($revision->id, null, $potentialId, 0);
    publishSweepIndicator($revision->id, null, $potentialId, 1);

    $violations = app(PublishRevision::class)->violations($revision->fresh());

    $subjects = array_column($violations, 'subject');
    expect($subjects)->toContain("competency:{$potentialId}");
});
