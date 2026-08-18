<?php

/**
 * RED — 8.5: Per-role BARS gap assertions (C3).
 *
 * After seeder run: asserts BUL/FLL/MLL bars_indicators row counts and
 * that ALL gap rows have status='pending_authoring'.
 *
 * Refs spec: BUL 42 + FLL 54 + MLL 54 bars_indicators rows, ZERO
 * competency_no_bars gaps for all three (bumped in
 * bars-catalogue-completion Phase 4 — PRS/DRV/COL/NET/SLF/COM/ITG/INC now
 * covered for every role that carries them, matching
 * scripts/framework-competency-gaps.txt (now empty) and
 * SeededCountCorrectnessTest.php's own bumped counts). FLL and MLL are
 * fully covered at 18/18 assigned competencies; BUL at 14/14.
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

test('all seeded gap rows have status=pending_authoring', function (): void {
    $total = FrameworkGap::count();
    $pending = FrameworkGap::where('status', 'pending_authoring')->count();

    expect($total)->toBeGreaterThan(0)
        ->and($pending)->toBe($total);
});
