<?php

/**
 * RED — 8.9: Delete-stale sync removes stale pivot and indicator rows (C3).
 *
 * Seeds full JSON, programmatically removes one competency from ICO's fixture,
 * re-seeds, asserts stale pivot AND indicators are gone.
 *
 * Refs spec: "Delete-stale — removing a competency from a role removes stale pivot and indicator rows".
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('re-seeding with one competency removed from a role deletes stale pivot and indicator rows', function (): void {
    // First seed — full data
    $seeder = new FrameworkCatalogSeeder();
    $seeder->run();

    // Identify a competency assigned to ICO that has bars indicators
    $ico = Role::where('code', 'ICO')->first();
    $pivotRow = DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->first();

    $targetCompetencyId = $pivotRow->competency_id;
    $targetCompetency = Competency::find($targetCompetencyId);
    $targetCode = $targetCompetency->code;

    // Confirm initial state
    $pivotCount = DB::table('framework_role_competency')->where('role_id', $ico->id)->count();
    $barsCount = BarsIndicator::where('role_id', $ico->id)->count();

    expect($pivotCount)->toBe(15); // ICO has 15 competencies

    // Build a modified ICO fixture with one competency removed
    $originalRolesJson = json_decode(
        file_get_contents(dirname(base_path()).'/docs/app_description/02-domain/framework/roles.json'),
        true
    );
    $modifiedRoles = $originalRolesJson;
    $modifiedRoles['ICO']['competencies'] = array_values(
        array_filter($modifiedRoles['ICO']['competencies'], fn ($c) => $c !== $targetCode)
    );

    // Write modified roles.json to a temp dir
    $tempDir = sys_get_temp_dir().'/c3_stalePivot_'.uniqid();
    mkdir($tempDir, 0755, true);
    file_put_contents("{$tempDir}/roles.json", json_encode($modifiedRoles));

    // Re-seed with modified roles and the seeder's injected rolesFile
    $seeder2 = new FrameworkCatalogSeeder(rolesFile: "{$tempDir}/roles.json");
    $seeder2->run();

    // Stale pivot for (ICO, targetCode) must be gone
    $newPivotCount = DB::table('framework_role_competency')->where('role_id', $ico->id)->count();
    expect($newPivotCount)->toBe(14); // one removed

    // Stale bars_indicators for (ICO, target) must be gone
    $staleIndicators = BarsIndicator::where('role_id', $ico->id)
        ->where('competency_id', $targetCompetencyId)
        ->count();
    expect($staleIndicators)->toBe(0);

    // Cleanup
    unlink("{$tempDir}/roles.json");
    rmdir($tempDir);
});
