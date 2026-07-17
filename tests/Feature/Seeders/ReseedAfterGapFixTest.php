<?php

/**
 * RED — 8.7: Re-seeding after correcting a gap adds missing rows (C3).
 *
 * Seeds with SRX bars absent, then adds a fake SRX.json, re-seeds,
 * asserts SRX indicators are inserted and other counts unchanged.
 *
 * Refs spec: "Re-seeding after correcting a gap adds the missing rows".
 */

use App\Models\BarsIndicator;
use App\Models\FrameworkGap;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

test('re-seeding after adding SRX bars inserts SRX indicators', function (): void {
    // First seed — SRX bars absent (seeder uses its barsDir arg)
    $tempDir = sys_get_temp_dir().'/c3_reseed_test_'.uniqid();
    mkdir($tempDir, 0755, true);

    $sourceBars = dirname(base_path()).'/docs/app_description/02-domain/framework/bars';
    foreach (['ICO', 'FLL', 'MLL', 'BUL'] as $role) {
        copy("{$sourceBars}/{$role}.json", "{$tempDir}/{$role}.json");
    }

    $seeder = new FrameworkCatalogSeeder(barsDir: $tempDir);
    $seeder->run();

    $srx = Role::where('code', 'SRX')->first();
    expect(BarsIndicator::where('role_id', $srx->id)->count())->toBe(0);

    // Counts before reseed (for other roles)
    $icoCount = BarsIndicator::where('role_id', Role::where('code', 'ICO')->first()->id)->count();

    // Add a minimal fake SRX.json (1 competency, 1 indicator)
    $srxBars = [
        'PRS' => [
            [
                'indicator' => 'SRX test indicator',
                'scale' => ['5' => 'SRX A5', '3' => 'SRX A3', '1' => 'SRX A1'],
            ],
        ],
    ];
    file_put_contents("{$tempDir}/SRX.json", json_encode($srxBars));

    // Re-seed with SRX.json now present
    $seeder->run();

    // SRX should now have indicators
    expect(BarsIndicator::where('role_id', $srx->id)->count())->toBeGreaterThan(0);

    // ICO count must be unchanged
    expect(BarsIndicator::where('role_id', Role::where('code', 'ICO')->first()->id)->count())->toBe($icoCount);

    // SRX role_no_bars gap should now be gone OR status updated (seeder uses updateOrCreate)
    // The gap may still exist but SRX now has indicators — that's acceptable per spec
    // The key assertion is that no exception was thrown and counts are correct

    // Cleanup
    array_map('unlink', glob("{$tempDir}/*.json") ?: []);
    rmdir($tempDir);
});
