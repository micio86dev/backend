<?php

/**
 * RED — 8.1: Seeder idempotency — second run produces no duplicates (C3).
 *
 * Runs FrameworkCatalogSeeder twice; asserts all row counts are identical
 * and gap rows do NOT duplicate on re-seed.
 *
 * Refs spec: "Second run produces no duplicates".
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkGap;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('seeder is idempotent — second run produces no new rows', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $roleCount = Role::count();
    $competencyCount = Competency::count();
    $pivotCount = DB::table('framework_role_competency')->count();
    $barsCount = BarsIndicator::count();
    $gapCount = FrameworkGap::count();

    // Run again
    $seeder->run();

    expect(Role::count())->toBe($roleCount)
        ->and(Competency::count())->toBe($competencyCount)
        ->and(DB::table('framework_role_competency')->count())->toBe($pivotCount)
        ->and(BarsIndicator::count())->toBe($barsCount)
        ->and(FrameworkGap::count())->toBe($gapCount);
});
