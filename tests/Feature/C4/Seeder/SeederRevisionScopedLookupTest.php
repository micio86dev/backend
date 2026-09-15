<?php

declare(strict_types=1);

/**
 * framework-catalogue-authoring PR2, tasks.md — "PR 1 review-gate landmine,
 * annotated not fixed (out of PR 1's scope)".
 *
 * `FrameworkCatalogSeeder` used to resolve natural-key rows by CODE ALONE:
 * `Competency::firstOrNew(['code' => $code])` and
 * `Role::firstOrNew(['code' => $roleCode])`. Safe only while a single
 * revision existed platform-wide — the moment a second revision can carry a
 * row with the same code (`UNIQUE(revision_id, code)`, not a bare
 * `UNIQUE(code)`), an unscoped lookup binds to WHICHEVER revision's row
 * Postgres happens to return first, silently.
 *
 * This test fails against the unscoped `firstOrNew(['code' => $code])` shape:
 * seeded into a fresh (empty) published baseline while a second, unrelated
 * revision already carries a same-coded row, the unscoped lookup finds that
 * OTHER revision's row, mutates it in place, and never creates the baseline's
 * own row at all — so `Role::where('revision_id', $baselineId)->where('code',
 * 'ICO')->exists()` comes back FALSE and the colliding row's name changes.
 * The fix scopes every lookup to `(revision_id, code)`.
 */

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

test('the seeder never mutates a same-coded row belonging to a different revision', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    expect(Competency::where('revision_id', $baseline->id)->exists())->toBeFalse('must start from a genuinely empty baseline to exercise the fresh-install path');

    // A second, unrelated revision — deliberately NOT the baseline this
    // seeder targets — carrying rows with the SAME natural-key codes the
    // real catalogue also uses ("ICO" role, and the real catalogue's own
    // first competency code, read from the fixture below).
    $otherRevision = FrameworkCatalogRevision::factory()->draft()->create();

    $collidingRole = Role::factory()->create([
        'revision_id' => $otherRevision->id,
        'code' => 'ICO',
        'name' => ['en' => 'COLLIDING REVISION ROLE NAME — MUST NOT CHANGE'],
        'responsibilities' => ['en' => 'COLLIDING REVISION RESPONSIBILITIES — MUST NOT CHANGE'],
    ]);

    $collidingCompetency = Competency::factory()->create([
        'revision_id' => $otherRevision->id,
        'code' => 'PRS',
        'type' => 'standard',
        'name' => ['en' => 'COLLIDING REVISION COMPETENCY NAME — MUST NOT CHANGE'],
        'definition' => ['en' => 'COLLIDING REVISION DEFINITION — MUST NOT CHANGE'],
    ]);

    (new FrameworkCatalogSeeder)->run();

    // The colliding revision's rows are byte-for-byte untouched.
    expect($collidingRole->fresh()->getTranslation('name', 'en'))->toBe('COLLIDING REVISION ROLE NAME — MUST NOT CHANGE');
    expect($collidingRole->fresh()->revision_id)->toBe($otherRevision->id);
    expect($collidingCompetency->fresh()->getTranslation('name', 'en'))->toBe('COLLIDING REVISION COMPETENCY NAME — MUST NOT CHANGE');
    expect($collidingCompetency->fresh()->revision_id)->toBe($otherRevision->id);

    // The baseline gained its OWN "ICO"/"PRS" rows, scoped to the baseline —
    // not merely a mutation of the colliding revision's rows.
    expect(Role::where('revision_id', $baseline->id)->where('code', 'ICO')->exists())->toBeTrue();
    expect(Competency::where('revision_id', $baseline->id)->where('code', 'PRS')->exists())->toBeTrue();

    // Exactly two "ICO" role rows exist platform-wide: the colliding one and
    // the baseline's own — never a single row shared/mutated across both.
    expect(Role::where('code', 'ICO')->count())->toBe(2);
    expect(Competency::where('code', 'PRS')->count())->toBe(2);
});
