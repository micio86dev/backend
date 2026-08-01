<?php

/**
 * RED — 8.5: Per-role BARS gap assertions (C3).
 *
 * After seeder run: asserts BUL/FLL/MLL competency_no_bars gaps and
 * that ALL gap rows have status='pending_authoring'.
 *
 * Refs spec: BUL 6 + FLL 10 + MLL 10 competency_no_bars gaps.
 */

use App\Models\BarsIndicator;
use App\Models\FrameworkGap;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('BUL has 24 bars_indicators rows and 6 competency_no_bars gaps', function (): void {
    $bul = Role::where('code', 'BUL')->first();
    expect(BarsIndicator::where('role_id', $bul->id)->count())->toBe(24);
    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'BUL')
            ->count()
    )->toBe(6);
});

test('FLL has 24 bars_indicators rows and 10 competency_no_bars gaps', function (): void {
    $fll = Role::where('code', 'FLL')->first();
    expect(BarsIndicator::where('role_id', $fll->id)->count())->toBe(24);
    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'FLL')
            ->count()
    )->toBe(10);
});

test('MLL has 24 bars_indicators rows and 10 competency_no_bars gaps', function (): void {
    $mll = Role::where('code', 'MLL')->first();
    expect(BarsIndicator::where('role_id', $mll->id)->count())->toBe(24);
    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'MLL')
            ->count()
    )->toBe(10);
});

test('all seeded gap rows have status=pending_authoring', function (): void {
    $total = FrameworkGap::count();
    $pending = FrameworkGap::where('status', 'pending_authoring')->count();

    expect($total)->toBeGreaterThan(0)
        ->and($pending)->toBe($total);
});
