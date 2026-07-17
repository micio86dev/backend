<?php

/**
 * RED — 8.4: MTG/LAT absent — potential catalog flagged incomplete (C3).
 *
 * After seeder run: asserts no MTG/LAT competency rows AND
 * gap entries exist for both.
 *
 * Refs spec: "MTG/LAT absent — potential catalog flagged incomplete".
 */

use App\Models\Competency;
use App\Models\FrameworkGap;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder())->run();
});

test('MTG competency is not created', function (): void {
    expect(Competency::where('code', 'MTG')->exists())->toBeFalse();
});

test('LAT competency is not created', function (): void {
    expect(Competency::where('code', 'LAT')->exists())->toBeFalse();
});

test('gap row exists for missing MTG potential competency', function (): void {
    expect(
        FrameworkGap::where('kind', 'missing_potential_competency')
            ->where('competency_code', 'MTG')
            ->exists()
    )->toBeTrue();
});

test('gap row exists for missing LAT potential competency', function (): void {
    expect(
        FrameworkGap::where('kind', 'missing_potential_competency')
            ->where('competency_code', 'LAT')
            ->exists()
    )->toBeTrue();
});
