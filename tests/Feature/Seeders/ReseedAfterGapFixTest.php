<?php

/**
 * 8.7 (tightened by 0.5): Re-seeding after correcting a gap adds missing
 * rows AND resolves the matching framework_gaps row (C3 fix 5a).
 *
 * Seeds with SRX bars absent, then adds a fake SRX.json, re-seeds,
 * asserts SRX indicators are inserted, other counts unchanged, and the
 * role_no_bars(SRX) gap is no longer pending_authoring.
 *
 * Refs spec: "Re-seeding after correcting a gap adds the missing rows";
 * "Gap Row Reconciliation on Seeded Content".
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

    // SRX role_no_bars gap must now be resolved (fix 5a) — no longer false that
    // it "may still exist" pending; the gap-row reconciliation requirement
    // makes closing the gap in the JSON and leaving it open in the DB a bug.
    expect(
        FrameworkGap::where('kind', 'role_no_bars')
            ->where('role_code', 'SRX')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeFalse();

    // Cleanup
    array_map('unlink', glob("{$tempDir}/*.json") ?: []);
    rmdir($tempDir);
});
