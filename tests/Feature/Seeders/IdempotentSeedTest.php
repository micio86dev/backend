<?php

/**
 * RED — 8.1: Seeder idempotency — second run produces no duplicates (C3).
 *
 * Runs FrameworkCatalogSeeder twice; asserts all row counts are identical
 * and gap rows do NOT duplicate on re-seed.
 *
 * Refs spec: "Second run produces no duplicates".
 *
 * framework-catalogue-authoring PR2 (D2): the SECOND run is now, by
 * construction, a run against a published baseline that already carries
 * content — the write gate blocks it entirely, which trivially satisfies
 * "no new catalogue rows". The one exception is `framework_gaps`: the gate
 * itself emits a `seeder_lock_guard_active` row the FIRST time it fires,
 * which the second run is. This test now proves idempotence across THREE
 * runs — run 2 adds exactly that one signal row (a real, expected write,
 * bookkeeping not catalogue content); run 3 proves the signal itself does
 * not duplicate.
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
    $gapCountAfterFirstRun = FrameworkGap::count();

    // Run again — writes are now blocked (published baseline, already has
    // content); the only expected change is the seeder_lock_guard_active
    // signal itself, emitted for the first time.
    $seeder->run();

    expect(Role::count())->toBe($roleCount)
        ->and(Competency::count())->toBe($competencyCount)
        ->and(DB::table('framework_role_competency')->count())->toBe($pivotCount)
        ->and(BarsIndicator::count())->toBe($barsCount)
        ->and(FrameworkGap::count())->toBe($gapCountAfterFirstRun + 1)
        ->and(FrameworkGap::where('kind', 'seeder_lock_guard_active')->count())->toBe(1);

    $gapCountAfterSecondRun = FrameworkGap::count();

    // A third run must not duplicate the signal, or anything else.
    $seeder->run();

    expect(Role::count())->toBe($roleCount)
        ->and(Competency::count())->toBe($competencyCount)
        ->and(DB::table('framework_role_competency')->count())->toBe($pivotCount)
        ->and(BarsIndicator::count())->toBe($barsCount)
        ->and(FrameworkGap::count())->toBe($gapCountAfterSecondRun)
        ->and(FrameworkGap::where('kind', 'seeder_lock_guard_active')->count())->toBe(1);
});
