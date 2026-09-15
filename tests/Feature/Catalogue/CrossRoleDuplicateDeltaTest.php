<?php

declare(strict_types=1);

/**
 * RED/GREEN — 10.4/11.3 (framework-catalogue-authoring PR3, D3,
 * Contradiction 3): a duplicate anchor text NEW to the revision is refused,
 * in both directions; the four inherited baseline duplicates
 * (`MLL.json:142`/`BUL.json:142`, `FLL.json:176`/`MLL.json:176`) publish
 * fine.
 */

use App\Actions\Catalogue\PublishRevision;
use App\Models\FrameworkCatalogRevision;
use Illuminate\Support\Facades\DB;

/**
 * Insert a role, a competency, their pivot row, and 3 indicators (one
 * shared "duplicate" text between the two roles, two role-unique) for a
 * given revision — every child row's role_id/competency_id are THAT
 * revision's own rows (composite FKs require role_id/competency_id to
 * belong to the SAME revision_id), matching what a real clone
 * (`OpenDraftRevision`) produces.
 *
 * @return array{roleAId: int, roleBId: int, competencyId: int}
 */
function crossRoleDeltaSeedRevision(int $revisionId, string $duplicateText): array
{
    $roleAId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revisionId, 'code' => 'XA', 'name' => json_encode(['en' => 'XA']),
        'responsibilities' => json_encode(['en' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleBId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $revisionId, 'code' => 'XB', 'name' => json_encode(['en' => 'XB']),
        'responsibilities' => json_encode(['en' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $revisionId, 'code' => 'XC', 'type' => 'standard',
        'name' => json_encode(['en' => 'XC']), 'definition' => json_encode(['en' => 'XC']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([$roleAId, $roleBId] as $roleId) {
        DB::table('framework_role_competency')->insert([
            'revision_id' => $revisionId, 'role_id' => $roleId, 'competency_id' => $competencyId, 'position' => 0,
        ]);
        DB::table('framework_bars_indicators')->insert([
            'revision_id' => $revisionId, 'role_id' => $roleId, 'competency_id' => $competencyId,
            'text' => json_encode(['en' => $duplicateText]),
            'anchor_5' => json_encode(['en' => 'a5']), 'anchor_3' => json_encode(['en' => 'a3']), 'anchor_1' => json_encode(['en' => 'a1']),
            'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('framework_bars_indicators')->insert([
            'revision_id' => $revisionId, 'role_id' => $roleId, 'competency_id' => $competencyId,
            'text' => json_encode(['en' => "unique to role {$roleId}"]),
            'anchor_5' => json_encode(['en' => 'a5']), 'anchor_3' => json_encode(['en' => 'a3']), 'anchor_1' => json_encode(['en' => 'a1']),
            'position' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('framework_bars_indicators')->insert([
            'revision_id' => $revisionId, 'role_id' => $roleId, 'competency_id' => $competencyId,
            'text' => json_encode(['en' => "also unique to role {$roleId}"]),
            'anchor_5' => json_encode(['en' => 'a5']), 'anchor_3' => json_encode(['en' => 'a3']), 'anchor_1' => json_encode(['en' => 'a1']),
            'position' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['roleAId' => $roleAId, 'roleBId' => $roleBId, 'competencyId' => $competencyId];
}

/**
 * @return array{parent: FrameworkCatalogRevision, child: FrameworkCatalogRevision, childRoleAId: int, childRoleBId: int}
 */
function crossRoleDeltaFixture(): array
{
    // Created draft so its content can be inserted — the content-
    // immutability trigger (framework-catalogue-authoring PR3) refuses an
    // INSERT into a published, non-baseline revision. Flipped to
    // `published` (raw update, bypassing the Eloquent guard) once its
    // content exists, mirroring what PublishRevision itself would leave.
    $parent = FrameworkCatalogRevision::factory()->draft()->create();
    crossRoleDeltaSeedRevision($parent->id, 'DUPLICATE TEXT');

    DB::table('framework_catalog_revisions')
        ->where('id', $parent->id)
        ->update(['state' => 'published', 'published_at' => now()]);

    $child = FrameworkCatalogRevision::factory()->draft()->create(['parent_revision_id' => $parent->id]);
    $childRows = crossRoleDeltaSeedRevision($child->id, 'DUPLICATE TEXT');

    return ['parent' => $parent, 'child' => $child, 'childRoleAId' => $childRows['roleAId'], 'childRoleBId' => $childRows['roleBId']];
}

test('an inherited cross-role duplicate is not flagged as new', function (): void {
    $fixture = crossRoleDeltaFixture();

    $violations = app(PublishRevision::class)->violations($fixture['child']);

    $rules = array_column($violations, 'rule');
    expect($rules)->not->toContain('cross_role_duplicate_anchor_new_to_revision');
});

test('a cross-role duplicate NEW to this revision is refused, in both directions', function (): void {
    $fixture = crossRoleDeltaFixture();

    // Introduce a NEW duplicate: overwrite role B's "unique" text (position 1)
    // to match role A's own "unique" text (position 1) — a duplicate that
    // exists in the CHILD but not in the parent.
    $roleAText = DB::table('framework_bars_indicators')
        ->where('revision_id', $fixture['child']->id)->where('role_id', $fixture['childRoleAId'])->where('position', 1)
        ->value('text');

    DB::table('framework_bars_indicators')
        ->where('revision_id', $fixture['child']->id)->where('role_id', $fixture['childRoleBId'])->where('position', 1)
        ->update(['text' => $roleAText]);

    $violations = app(PublishRevision::class)->violations($fixture['child']->fresh());

    $rules = array_column($violations, 'rule');
    expect($rules)->toContain('cross_role_duplicate_anchor_new_to_revision');
});
