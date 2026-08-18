<?php

declare(strict_types=1);

/**
 * 0.6: Seeded completeness — every (role, competency) pair present in the
 * source JSON has exactly 3 indicator rows in the DB (C3).
 *
 * Derived from the JSON, not hardcoded counts: catches the seeder silently
 * dropping rows for a covered pair, independent of how many pairs are
 * currently anchored. Complements SeededCountCorrectnessTest.php (which
 * asserts the hardcoded per-role totals bumped in each content PR).
 *
 * Refs: design.md §7 Testing strategy.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

test('every covered (role, competency) pair has exactly 3 indicator rows', function (): void {
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';

    (new FrameworkCatalogSeeder)->run();

    $checked = 0;

    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        $roleCode = pathinfo($barsFile, PATHINFO_FILENAME);
        $role = Role::where('code', $roleCode)->first();

        if ($role === null) {
            continue;
        }

        $barsJson = json_decode(file_get_contents($barsFile), true, 512, JSON_THROW_ON_ERROR);

        foreach ($barsJson as $competencyCode => $indicatorArray) {
            $competency = Competency::where('code', $competencyCode)->first();

            if ($competency === null) {
                continue;
            }

            $count = BarsIndicator::where('role_id', $role->id)
                ->where('competency_id', $competency->id)
                ->count();

            expect($count)->toBe(3, "Expected exactly 3 indicator rows for {$roleCode}:{$competencyCode}, got {$count}");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0, 'fixture precondition: at least one covered pair must exist to check');
});

test('a competency with fewer than 3 declared anchors is caught (self-test fixture)', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_completeness_selftest_'.uniqid();
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';

    @mkdir("{$tmpDir}/bars", 0755, true);
    file_put_contents("{$tmpDir}/roles.json", file_get_contents("{$frameworkBase}/roles.json"));
    file_put_contents("{$tmpDir}/competencies.json", file_get_contents("{$frameworkBase}/competencies.json"));
    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    // Break ICO's first BARS entry down to 2 indicators instead of 3.
    $icoBars = json_decode(file_get_contents("{$tmpDir}/bars/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $firstCompetency = array_key_first($icoBars);
    $icoBars[$firstCompetency] = array_slice($icoBars[$firstCompetency], 0, 2);
    file_put_contents("{$tmpDir}/bars/ICO.json", json_encode($icoBars));

    (new FrameworkCatalogSeeder("{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $competency = Competency::where('code', $firstCompetency)->firstOrFail();
    $count = BarsIndicator::where('role_id', $ico->id)->where('competency_id', $competency->id)->count();

    expect($count)->toBe(2, 'self-test fixture precondition: seeder mirrors the JSON exactly (2 rows), proving the completeness assertion above would have caught this as a real defect');

    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
});
