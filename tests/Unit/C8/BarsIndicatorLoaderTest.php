<?php

declare(strict_types=1);

/**
 * RED — Tasks 2.1–2.3: BarsIndicatorLoader (C8 Phase 2).
 *
 * Verifies:
 * (a) forRoleCompetency(fll_role_id, col_competency_id) → only FLL indicators; no MLL.
 * (b) forRoleCompetency(mll_role_id, col_competency_id) → only MLL indicators; no FLL.
 * (c) Role with no indicators for the competency → empty Collection.
 * (d) Indicators are ordered by position (ascending).
 * (e) Cross-role contamination is impossible — the two sets are fully disjoint.
 *
 * Spec: REQ BarsIndicatorLoader · Scenarios: cross-role isolation, position order.
 * REQ: BarsIndicatorLoader (C8 Phase 2 — RV-2)
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use Illuminate\Support\Collection;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Create a BarsIndicator with full EN translations for testing.
 *
 * @param  array<string, mixed>  $overrides
 */
function loaderMakeIndicator(int $roleId, int $competencyId, int $position, array $overrides = []): BarsIndicator
{
    $indicator = new BarsIndicator;
    $indicator->forceFill(array_merge([
        'role_id'       => $roleId,
        'competency_id' => $competencyId,
        'text'          => ['en' => "Indicator text {$roleId}-{$position}"],
        'anchor_5'      => ['en' => "Anchor 5 for {$roleId}-{$position}"],
        'anchor_3'      => ['en' => "Anchor 3 for {$roleId}-{$position}"],
        'anchor_1'      => ['en' => "Anchor 1 for {$roleId}-{$position}"],
        'position'      => $position,
    ], $overrides));
    $indicator->save();

    return $indicator;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) forRoleCompetency returns only FLL indicators; no MLL indicator present', function (): void {
    $fll = Role::factory()->create(['code' => 'FLL_'.uniqid()]);
    $mll = Role::factory()->create(['code' => 'MLL_'.uniqid()]);
    $col = Competency::factory()->create(['code' => 'COL_'.uniqid()]);

    $fllInd = loaderMakeIndicator($fll->id, $col->id, 0);
    $mllInd = loaderMakeIndicator($mll->id, $col->id, 0);

    $loader = new BarsIndicatorLoader;
    $result = $loader->forRoleCompetency($fll->id, $col->id);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($fllInd->id);

    // MLL indicator MUST NOT appear
    $ids = $result->pluck('id')->all();
    expect($ids)->not->toContain($mllInd->id);
});

test('(b) forRoleCompetency returns only MLL indicators; no FLL indicator present', function (): void {
    $fll = Role::factory()->create(['code' => 'FLL2_'.uniqid()]);
    $mll = Role::factory()->create(['code' => 'MLL2_'.uniqid()]);
    $col = Competency::factory()->create(['code' => 'COL2_'.uniqid()]);

    $fllInd = loaderMakeIndicator($fll->id, $col->id, 0);
    $mllInd = loaderMakeIndicator($mll->id, $col->id, 0);

    $loader = new BarsIndicatorLoader;
    $result = $loader->forRoleCompetency($mll->id, $col->id);

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($mllInd->id);

    $ids = $result->pluck('id')->all();
    expect($ids)->not->toContain($fllInd->id);
});

test('(c) forRoleCompetency with no indicators for the given competency returns empty Collection', function (): void {
    $role       = Role::factory()->create(['code' => 'EMPTY_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'EMPTY_'.uniqid()]);
    // No indicators created for this role+competency pair

    $loader = new BarsIndicatorLoader;
    $result = $loader->forRoleCompetency($role->id, $competency->id);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(0);
});

test('(d) indicators are ordered by position ascending', function (): void {
    $role       = Role::factory()->create(['code' => 'ORD_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'ORD_'.uniqid()]);

    // Insert in reverse position order
    $ind2 = loaderMakeIndicator($role->id, $competency->id, 2);
    $ind0 = loaderMakeIndicator($role->id, $competency->id, 0);
    $ind1 = loaderMakeIndicator($role->id, $competency->id, 1);

    $loader = new BarsIndicatorLoader;
    $result = $loader->forRoleCompetency($role->id, $competency->id);

    expect($result)->toHaveCount(3);
    expect($result->values()->get(0)->id)->toBe($ind0->id);
    expect($result->values()->get(1)->id)->toBe($ind1->id);
    expect($result->values()->get(2)->id)->toBe($ind2->id);
});

test('(e) cross-role isolation: FLL and MLL sets are fully disjoint for the same competency', function (): void {
    $fll = Role::factory()->create(['code' => 'FLL3_'.uniqid()]);
    $mll = Role::factory()->create(['code' => 'MLL3_'.uniqid()]);
    $col = Competency::factory()->create(['code' => 'COL3_'.uniqid()]);

    // 3 FLL indicators, 2 MLL indicators — disjoint by construction
    $fllInds = collect([
        loaderMakeIndicator($fll->id, $col->id, 0),
        loaderMakeIndicator($fll->id, $col->id, 1),
        loaderMakeIndicator($fll->id, $col->id, 2),
    ]);

    $mllInds = collect([
        loaderMakeIndicator($mll->id, $col->id, 0),
        loaderMakeIndicator($mll->id, $col->id, 1),
    ]);

    $loader = new BarsIndicatorLoader;

    $fllResult = $loader->forRoleCompetency($fll->id, $col->id);
    $mllResult = $loader->forRoleCompetency($mll->id, $col->id);

    $fllIds = $fllResult->pluck('id')->all();
    $mllIds = $mllResult->pluck('id')->all();

    // The two ID sets must be disjoint
    expect(array_intersect($fllIds, $mllIds))->toBe([]);

    // Each result must only contain its own role's indicators
    expect($fllResult)->toHaveCount(3);
    expect($mllResult)->toHaveCount(2);

    foreach ($mllInds as $mllInd) {
        expect($fllIds)->not->toContain($mllInd->id);
    }

    foreach ($fllInds as $fllInd) {
        expect($mllIds)->not->toContain($fllInd->id);
    }
});
