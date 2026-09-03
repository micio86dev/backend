<?php

/**
 * RED — 8.5: Per-role BARS gap assertions (C3).
 *
 * After seeder run: asserts BUL/FLL/MLL bars_indicators row counts and
 * that every gap row NOT about `it` translation completeness has
 * status='pending_authoring'.
 *
 * Refs spec: BUL 42 + FLL 54 + MLL 54 bars_indicators rows, ZERO
 * competency_no_bars gaps for all three (bumped in
 * bars-catalogue-completion Phase 4 — PRS/DRV/COL/NET/SLF/COM/ITG/INC now
 * covered for every role that carries them, matching
 * scripts/framework-competency-gaps.txt (now empty) and
 * SeededCountCorrectnessTest.php's own bumped counts). FLL and MLL are
 * fully covered at 18/18 assigned competencies; BUL at 14/14.
 *
 * "all gap rows are pending_authoring" stopped being true once the wrapper
 * catalogue's `it` locale reached 100% coverage (bars-catalogue-completion):
 * seeding the real catalogue now legitimately RESOLVES the global
 * `missing_translation` row (see FrameworkCatalogSeeder step 5 / design D5).
 * The prior version of this test unintentionally encoded the production
 * defect it was meant to guard against — a catalogue complete from the
 * start could never actually reach `resolved`, so "every gap row is
 * pending" always held BY ACCIDENT, not by design. Only
 * `missing_potential_competency` (MTG, LAT — no expert-authored definition
 * exists yet) legitimately stays pending against today's real catalogue.
 */

use App\Models\BarsIndicator;
use App\Models\FrameworkGap;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('BUL has 42 bars_indicators rows and 0 competency_no_bars gaps', function (): void {
    $bul = Role::where('code', 'BUL')->first();
    expect(BarsIndicator::where('role_id', $bul->id)->count())->toBe(42);
    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'BUL')
            ->count()
    )->toBe(0);
});

test('FLL has 54 bars_indicators rows and 0 competency_no_bars gaps', function (): void {
    $fll = Role::where('code', 'FLL')->first();
    expect(BarsIndicator::where('role_id', $fll->id)->count())->toBe(54);
    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'FLL')
            ->count()
    )->toBe(0);
});

test('MLL has 54 bars_indicators rows and 0 competency_no_bars gaps', function (): void {
    $mll = Role::where('code', 'MLL')->first();
    expect(BarsIndicator::where('role_id', $mll->id)->count())->toBe(54);
    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'MLL')
            ->count()
    )->toBe(0);
});

test('all seeded gap rows are pending_authoring, except missing_translation which resolves against the fully it-translated catalogue', function (): void {
    $total = FrameworkGap::count();
    $nonTranslationTotal = FrameworkGap::where('kind', '!=', 'missing_translation')->count();
    $nonTranslationPending = FrameworkGap::where('kind', '!=', 'missing_translation')
        ->where('status', 'pending_authoring')
        ->count();

    // `$nonTranslationTotal` is no longer required to be > 0. It used to be,
    // because MTG and LAT were unauthored and the seeder wrote a
    // `missing_potential_competency` row for each on every run. Both are
    // authored now (potential-competencies-and-authored-questions), so on a
    // fresh catalogue that gap is never created at all — which is the correct
    // state, not a missing row.
    //
    // What still holds, and is what this test is actually for: any
    // non-translation gap that DOES exist must be pending. A resolved one of
    // those kinds would mean something marked itself fixed without being
    // reauthored.
    expect($total)->toBeGreaterThan(0)
        ->and($nonTranslationPending)->toBe($nonTranslationTotal, 'every non-translation gap kind that exists must still be pending');

    // The real catalogue ships 100% it-translated (bars-catalogue-completion),
    // so the global missing_translation row must be resolved, with a note
    // that truthfully states the total — never "0 of 0", which would be the
    // symptom of the production defect this scenario guards against.
    $globalTranslationGap = FrameworkGap::where('kind', 'missing_translation')
        ->whereNull('role_code')->whereNull('competency_code')->first();

    expect($globalTranslationGap)->not->toBeNull()
        ->and($globalTranslationGap->status)->toBe('resolved')
        ->and($globalTranslationGap->note)->not->toContain('0 of 0');
});
