<?php

declare(strict_types=1);

/**
 * H9 (framework-catalogue-authoring PR3b, R3-010): the publish sweep checks
 * indicators against the pivot in both directions the pre-existing checks
 * missed:
 *   - an indicator SET exists for a `(role, competency)` pair the pivot
 *     never declared (`indicatorCountViolations()` only groups indicators
 *     that already exist and checks their COUNT, so an undeclared pair that
 *     happens to carry exactly 3 indicators produced zero violations);
 *   - a role-LESS indicator belongs to a `standard` competency (role-less
 *     indicators are legal ONLY for `potential` competencies — the mirror
 *     image of `roleScopedPotentialIndicatorViolations()`).
 */

use App\Actions\Catalogue\PublishRevision;
use App\Models\FrameworkCatalogRevision;
use Illuminate\Support\Facades\DB;

function h9Indicator(int $revisionId, ?int $roleId, int $competencyId, int $position): void
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

test('a fully-anchored (role, competency) pair NEVER declared in the pivot refuses publish', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $roleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'H9ROLE', 'name' => json_encode(['en' => 'x']),
        'responsibilities' => json_encode(['en' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'H9COMP', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Exactly 3 indicators — passes `indicatorCountViolations()` cleanly —
    // but NO row in `framework_role_competency` declares this pair at all.
    h9Indicator($revision->id, $roleId, $competencyId, 0);
    h9Indicator($revision->id, $roleId, $competencyId, 1);
    h9Indicator($revision->id, $roleId, $competencyId, 2);

    $violations = app(PublishRevision::class)->violations($revision->fresh());

    $rules = array_column($violations, 'rule');
    $subjects = array_column($violations, 'subject');

    expect($rules)->toContain('indicator_pair_not_declared');
    expect($subjects)->toContain("role:{$roleId} competency:{$competencyId}");
});

test('a declared pair with exactly 3 indicators contributes no undeclared-pair violation', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $roleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'H9ROLEOK', 'name' => json_encode(['en' => 'x']),
        'responsibilities' => json_encode(['en' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'H9COMPOK', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_role_competency')->insert([
        'revision_id' => $revision->id, 'role_id' => $roleId, 'competency_id' => $competencyId, 'position' => 0,
    ]);

    h9Indicator($revision->id, $roleId, $competencyId, 0);
    h9Indicator($revision->id, $roleId, $competencyId, 1);
    h9Indicator($revision->id, $roleId, $competencyId, 2);

    $violations = app(PublishRevision::class)->violations($revision->fresh());

    expect(array_column($violations, 'rule'))->not->toContain('indicator_pair_not_declared');
});

test('a role-less indicator belonging to a standard competency refuses publish', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'H9STDROLELESS', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $indicatorId = DB::table('framework_bars_indicators')->insertGetId([
        'revision_id' => $revision->id, 'role_id' => null, 'competency_id' => $competencyId,
        'text' => json_encode(['en' => 'x']), 'anchor_5' => json_encode(['en' => 'x']),
        'anchor_3' => json_encode(['en' => 'x']), 'anchor_1' => json_encode(['en' => 'x']),
        'position' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $violations = app(PublishRevision::class)->violations($revision->fresh());

    $rules = array_column($violations, 'rule');
    $subjects = array_column($violations, 'subject');

    expect($rules)->toContain('standard_indicator_must_have_role');
    expect($subjects)->toContain("indicator:{$indicatorId}");
});

test('a role-less indicator belonging to a potential competency contributes no such violation', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revision->id, 'code' => 'H9POTOK', 'type' => 'potential',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    h9Indicator($revision->id, null, $competencyId, 0);

    $violations = app(PublishRevision::class)->violations($revision->fresh());

    expect(array_column($violations, 'rule'))->not->toContain('standard_indicator_must_have_role');
});
