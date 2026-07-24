<?php

/**
 * RED — 8.2: Missing bars/SRX.json is skipped gracefully (C3).
 *
 * Fakes the file system so SRX bars file is absent; asserts seeder completes
 * without exception, SRX role exists, SRX bars_indicators=0, gap recorded.
 *
 * Refs spec: "Missing bars/SRX.json is skipped gracefully".
 */

use App\Models\BarsIndicator;
use App\Models\FrameworkGap;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

test('seeder completes gracefully when bars/SRX.json is absent', function (): void {
    // Override the bars path to a temp dir that has no SRX.json
    $tempDir = sys_get_temp_dir().'/c3_test_bars_'.uniqid();
    mkdir($tempDir, 0755, true);

    // Copy all bars files EXCEPT SRX.json
    $sourceBars = dirname(base_path()).'/docs/app_description/02-domain/framework/bars';
    foreach (['ICO', 'FLL', 'MLL', 'BUL'] as $role) {
        copy("{$sourceBars}/{$role}.json", "{$tempDir}/{$role}.json");
    }

    $seeder = new FrameworkCatalogSeeder(barsDir: $tempDir);

    // Must NOT throw
    expect(fn () => $seeder->run())->not->toThrow(Exception::class);

    // SRX role must still be seeded from roles.json
    expect(Role::where('code', 'SRX')->exists())->toBeTrue();

    // SRX must have 0 bars_indicators
    $srx = Role::where('code', 'SRX')->first();
    expect(BarsIndicator::where('role_id', $srx->id)->count())->toBe(0);

    // A gap row must be recorded for SRX
    expect(
        FrameworkGap::where('kind', 'role_no_bars')
            ->where('role_code', 'SRX')
            ->exists()
    )->toBeTrue();

    // Cleanup
    array_map('unlink', glob("{$tempDir}/*.json") ?: []);
    rmdir($tempDir);
});
