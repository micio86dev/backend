<?php

declare(strict_types=1);

/**
 * RED/GREEN — 4.3 (framework-catalogue-authoring PR 1): two revisions with
 * divergent anchor text; a project pinned to revision 1 always resolves
 * revision 1's text regardless of revision 2's later publish. See
 * design.md — "Modify BarsIndicatorLoader.php: resolve indicators through
 * Project -> FrameworkVersion -> revision_id, never the single live
 * catalogue."
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use Illuminate\Support\Facades\DB;

test('the loader resolves the exact revision it is asked for, never a different one', function (): void {
    $revision1Id = DB::table('framework_catalog_revisions')->first()->id; // the baseline
    $revision2Id = DB::table('framework_catalog_revisions')->insertGetId([
        'state' => 'published',
        'is_baseline' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $role1 = Role::factory()->create(['code' => 'RES_ROLE', 'revision_id' => $revision1Id]);
    $competency1 = Competency::factory()->create(['code' => 'RES_COMP', 'revision_id' => $revision1Id]);
    $indicator1 = BarsIndicator::create([
        'role_id' => $role1->id,
        'competency_id' => $competency1->id,
        'revision_id' => $revision1Id,
        'text' => ['en' => 'Revision 1 question text.'],
        'anchor_5' => ['en' => 'Revision 1 anchor 5.'],
        'anchor_3' => ['en' => 'Revision 1 anchor 3.'],
        'anchor_1' => ['en' => 'Revision 1 anchor 1.'],
        'position' => 0,
    ]);

    // A SEPARATE role/competency/indicator set for revision 2, reusing the
    // SAME codes (legal — code is unique per revision now) with DIVERGENT
    // anchor text, mirroring what a superadmin's draft edit would produce.
    $role2 = Role::factory()->create(['code' => 'RES_ROLE', 'revision_id' => $revision2Id]);
    $competency2 = Competency::factory()->create(['code' => 'RES_COMP', 'revision_id' => $revision2Id]);
    $indicator2 = BarsIndicator::create([
        'role_id' => $role2->id,
        'competency_id' => $competency2->id,
        'revision_id' => $revision2Id,
        'text' => ['en' => 'Revision 2 EDITED question text.'],
        'anchor_5' => ['en' => 'Revision 2 EDITED anchor 5.'],
        'anchor_3' => ['en' => 'Revision 2 EDITED anchor 3.'],
        'anchor_1' => ['en' => 'Revision 2 EDITED anchor 1.'],
        'position' => 0,
    ]);

    $loader = new BarsIndicatorLoader;

    $resolvedFromRevision1 = $loader->forRoleCompetency($role1->id, $competency1->id, $revision1Id);
    expect($resolvedFromRevision1)->toHaveCount(1);
    expect($resolvedFromRevision1->first()->id)->toBe($indicator1->id);
    expect($resolvedFromRevision1->first()->getTranslation('text', 'en'))->toBe('Revision 1 question text.');

    $resolvedFromRevision2 = $loader->forRoleCompetency($role2->id, $competency2->id, $revision2Id);
    expect($resolvedFromRevision2)->toHaveCount(1);
    expect($resolvedFromRevision2->first()->id)->toBe($indicator2->id);
    expect($resolvedFromRevision2->first()->getTranslation('text', 'en'))->toBe('Revision 2 EDITED question text.');

    // A project pinned to revision 1 (via role1/competency1's ids) is
    // unaffected by revision 2's later publish: resolving with an EXPLICIT,
    // mismatched revisionId returns nothing rather than another revision's
    // content — the defensive, explicit-scope guarantee the loader exists
    // to provide.
    $mismatched = $loader->forRoleCompetency($role1->id, $competency1->id, $revision2Id);
    expect($mismatched)->toBeEmpty();

    // Omitting revisionId entirely preserves today's exact behaviour
    // (backward-compatible default for every existing, revision-unaware
    // caller) — resolves by role_id/competency_id alone, which is already
    // revision-unique because a draft clones full rows rather than reusing ids.
    $noRevisionFilter = $loader->forRoleCompetency($role1->id, $competency1->id);
    expect($noRevisionFilter)->toHaveCount(1);
    expect($noRevisionFilter->first()->id)->toBe($indicator1->id);
});
