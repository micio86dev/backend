<?php

declare(strict_types=1);

/**
 * RED — 4.1: Seeder lock-guard — fully additive when a locked FV exists (C4).
 *
 * Scenarios:
 * 1. Locked FV + anchor edit → existing anchor row UNCHANGED; competency name UNCHANGED.
 * 2. Locked FV + new competency Z in JSON → Z is inserted; indicators inserted; pivot created via syncWithoutDetaching.
 * 3. Locked FV + JSON-removed competency W → BarsIndicator::delete() suppressed; continue preserved; W's pivot + indicators untouched.
 * 4. Locked FV → framework_gaps upserts still run.
 * 5. Locked FV → seeder_lock_guard_active signal emitted.
 * 6. Soft-deleted project → FV still is_locked=true → guard fires.
 * 7. No locked FV → normal delete-stale + mutations fire.
 * 8. structuralChange + CatalogMeta::bump(): fires only on genuine new-row insert, not on suppressed mutation.
 *
 * Refs spec: lock-guard — fully additive; spec scenarios in framework-catalog spec delta.
 */

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\FrameworkGap;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

// ─── helpers ─────────────────────────────────────────────────────────────────

/**
 * Build a minimal competencies.json file and a bars directory in $tmpDir.
 * Merges $extraCompetencies into the existing competencies.json if provided.
 * Returns [rolesFile, competenciesFile, barsDir] paths.
 *
 * @param  array<string, array{name: string, definition: string}>  $extraCompetencies  Additional competency codes to add
 * @param  array<string, array{name: string, responsibilities: string, competencies: list<string>}>  $roleOverrides  Role overrides
 * @return array{string, string, string}
 */
function buildSeederFixtures(string $tmpDir, array $extraCompetencies = [], array $roleOverrides = []): array
{
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    $originalRoles = json_decode(file_get_contents("{$frameworkBase}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    $originalCompetencies = json_decode(file_get_contents("{$frameworkBase}/competencies.json"), true, 512, JSON_THROW_ON_ERROR);

    $roles = array_merge($originalRoles, $roleOverrides);
    $competencies = array_merge($originalCompetencies, $extraCompetencies);

    @mkdir("{$tmpDir}/bars", 0755, true);
    file_put_contents("{$tmpDir}/roles.json", json_encode($roles));
    file_put_contents("{$tmpDir}/competencies.json", json_encode($competencies));

    // Copy all BARS files into the tmp dir
    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

// ─── Scenario 1: Locked FV — anchor edit is suppressed, name edit is suppressed ──

test('locked FV: anchor text and competency name edits are suppressed on re-seed', function (): void {
    // First seed normally — no locked FV
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    // Pin a FV (lock it)
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    // Capture original anchor text for a known indicator
    $ico = Role::where('code', 'ICO')->firstOrFail();
    $firstCompetency = DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->join('framework_competencies', 'framework_competencies.id', '=', 'framework_role_competency.competency_id')
        ->first();
    $competencyCode = $firstCompetency->code;
    $competencyId = $firstCompetency->competency_id;

    $originalIndicator = BarsIndicator::where('role_id', $ico->id)
        ->where('competency_id', $competencyId)
        ->orderBy('position')
        ->first();

    expect($originalIndicator)->not->toBeNull();
    $originalAnchor5 = $originalIndicator->getTranslation('anchor_5', 'en');
    $originalName = Competency::find($competencyId)->getTranslation('name', 'en');

    // Build a modified fixtures with edited anchor + name
    $tmpDir = sys_get_temp_dir().'/c4_lock_guard_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildSeederFixtures($tmpDir);

    // Modify anchor_5 for the first indicator of this competency in the BARS file
    $barsFilePath = "{$barsDir}/ICO.json";
    $barsData = json_decode(file_get_contents($barsFilePath), true, 512, JSON_THROW_ON_ERROR);
    if (isset($barsData[$competencyCode])) {
        $barsData[$competencyCode][0]['scale']['5']['en'] = 'MODIFIED ANCHOR TEXT';
    }
    file_put_contents($barsFilePath, json_encode($barsData));

    // Edit competency name in competencies.json
    $compData = json_decode(file_get_contents($competenciesFile), true, 512, JSON_THROW_ON_ERROR);
    if (isset($compData[$competencyCode])) {
        $compData[$competencyCode]['name']['en'] = 'MODIFIED NAME';
    }
    file_put_contents($competenciesFile, json_encode($compData));

    // Re-seed while locked FV exists
    $seeder2 = new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir);
    $seeder2->run();

    // Assert: existing anchor unchanged
    $freshIndicator = $originalIndicator->fresh();
    expect($freshIndicator->getTranslation('anchor_5', 'en'))->toBe($originalAnchor5);

    // Assert: existing competency name unchanged
    $freshCompetency = Competency::find($competencyId)->fresh();
    expect($freshCompetency->getTranslation('name', 'en'))->toBe($originalName);

    // Cleanup
    array_map('unlink', glob("{$tmpDir}/bars/*"));
    rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json"));
    rmdir($tmpDir);
});

// ─── Scenario 2: Locked FV — new competency Z is inserted (additive) ──────────

test('locked FV: new competency Z added to JSON is inserted (additive)', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    // Verify Z does not exist yet
    expect(Competency::where('code', 'ZZZ')->exists())->toBeFalse();

    $tmpDir = sys_get_temp_dir().'/c4_lock_guard_z_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildSeederFixtures($tmpDir, [
        'ZZZ' => ['name' => ['en' => 'Test Competency Z'], 'definition' => ['en' => 'Test definition']],
    ]);

    $seeder2 = new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir);
    $seeder2->run();

    // Z must now exist in the DB (new row inserted)
    expect(Competency::where('code', 'ZZZ')->exists())->toBeTrue();

    // Cleanup
    array_map('unlink', glob("{$tmpDir}/bars/*"));
    rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json"));
    rmdir($tmpDir);
});

// ─── Scenario 3: Locked FV — JSON-removed competency W leaves indicators/pivot intact ──

test('locked FV: JSON-removed competency W leaves indicators and pivot intact', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    // Identify a competency in ICO that has bars indicators
    $ico = Role::where('code', 'ICO')->firstOrFail();
    $pivotRow = DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->join('framework_competencies', 'framework_competencies.id', '=', 'framework_role_competency.competency_id')
        ->first();
    $removedCode = $pivotRow->code;
    $removedId = $pivotRow->competency_id;

    $indicatorCount = BarsIndicator::where('role_id', $ico->id)
        ->where('competency_id', $removedId)
        ->count();

    // Build fixtures with W removed from ICO roles
    $tmpDir = sys_get_temp_dir().'/c4_lock_guard_w_'.uniqid();
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    $originalRoles = json_decode(file_get_contents("{$frameworkBase}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    $originalRoles['ICO']['competencies'] = array_values(
        array_filter($originalRoles['ICO']['competencies'], fn ($c) => $c !== $removedCode)
    );

    @mkdir("{$tmpDir}/bars", 0755, true);
    file_put_contents("{$tmpDir}/roles.json", json_encode($originalRoles));
    $origComp = file_get_contents("{$frameworkBase}/competencies.json");
    file_put_contents("{$tmpDir}/competencies.json", $origComp);
    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    $seeder2 = new FrameworkCatalogSeeder("{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars");
    $seeder2->run();

    // Pivot for (ICO, W) must still exist — delete-stale suppressed
    $pivotExists = DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->where('competency_id', $removedId)
        ->exists();
    expect($pivotExists)->toBeTrue('DB pivot for W must be preserved when FV is locked');

    // Indicators for (ICO, W) must still exist
    $freshIndicatorCount = BarsIndicator::where('role_id', $ico->id)
        ->where('competency_id', $removedId)
        ->count();
    expect($freshIndicatorCount)->toBe($indicatorCount, 'BarsIndicator rows for W must be preserved when FV is locked');

    // Cleanup
    array_map('unlink', glob("{$tmpDir}/bars/*"));
    rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json"));
    rmdir($tmpDir);
});

// ─── Scenario 4: Locked FV — framework_gaps upserts still run ─────────────────

test('locked FV: framework_gaps upserts still run', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    // Clear all framework gaps to confirm they are re-created
    FrameworkGap::query()->delete();
    expect(FrameworkGap::count())->toBe(0);

    $seeder2 = new FrameworkCatalogSeeder;
    $seeder2->run();

    // Gaps should have been re-upserted
    expect(FrameworkGap::count())->toBeGreaterThan(0);
});

// ─── Scenario 5: Locked FV — seeder_lock_guard_active signal emitted ──────────

test('locked FV: seeder_lock_guard_active signal is emitted', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    // Remove the signal if it already exists from the first run
    FrameworkGap::where('kind', 'seeder_lock_guard_active')->delete();

    $seeder2 = new FrameworkCatalogSeeder;
    $seeder2->run();

    // Signal must be present as a FrameworkGap record
    expect(FrameworkGap::where('kind', 'seeder_lock_guard_active')->exists())->toBeTrue();
});

// ─── Scenario 6: Soft-deleted project — FV still locked → guard fires ─────────

test('soft-deleted project keeps FV locked; guard still fires', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    // Create a project that pins this FV, then soft-delete it
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $project->delete();

    // Verify is_locked is still true after soft-delete
    $freshFv = $fv->fresh();
    expect($freshFv->is_locked)->toBeTrue();

    // Remove the guard signal to confirm it fires again
    FrameworkGap::where('kind', 'seeder_lock_guard_active')->delete();

    $seeder2 = new FrameworkCatalogSeeder;
    $seeder2->run();

    // Guard must have fired (signal emitted)
    expect(FrameworkGap::where('kind', 'seeder_lock_guard_active')->exists())->toBeTrue();
});

// ─── Scenario 7: No locked FV → normal delete-stale + mutations fire ──────────

test('no locked FV: normal delete-stale and mutations fire', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    // Ensure no locked FVs exist
    expect(FrameworkVersion::withoutGlobalScopes()->where('is_locked', true)->exists())->toBeFalse();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $pivotRow = DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->join('framework_competencies', 'framework_competencies.id', '=', 'framework_role_competency.competency_id')
        ->first();
    $removedCode = $pivotRow->code;
    $removedId = $pivotRow->competency_id;

    // Build modified fixture with one ICO competency removed
    $tmpDir = sys_get_temp_dir().'/c4_no_lock_'.uniqid();
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    $originalRoles = json_decode(file_get_contents("{$frameworkBase}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    $originalRoles['ICO']['competencies'] = array_values(
        array_filter($originalRoles['ICO']['competencies'], fn ($c) => $c !== $removedCode)
    );

    @mkdir("{$tmpDir}/bars", 0755, true);
    file_put_contents("{$tmpDir}/roles.json", json_encode($originalRoles));
    file_put_contents("{$tmpDir}/competencies.json", file_get_contents("{$frameworkBase}/competencies.json"));
    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    $seeder2 = new FrameworkCatalogSeeder("{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars");
    $seeder2->run();

    // Stale pivot must be deleted (no lock guard, normal mode)
    $pivotExists = DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->where('competency_id', $removedId)
        ->exists();
    expect($pivotExists)->toBeFalse('Stale pivot must be deleted when no FV is locked');

    // Cleanup
    array_map('unlink', glob("{$tmpDir}/bars/*"));
    rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json"));
    rmdir($tmpDir);
});

// ─── Scenario 8: CatalogMeta::bump() fires only on new-row insert ──────────────

test('locked FV: CatalogMeta::bump() fires only on genuine new-row insert', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    $bumpBefore = CatalogMeta::first()?->revision ?? 0;

    // Re-seed without any new rows → no new insertions → bump should NOT fire
    $seeder2 = new FrameworkCatalogSeeder;
    $seeder2->run();

    $bumpAfter = CatalogMeta::first()?->revision ?? 0;
    expect($bumpAfter)->toBe($bumpBefore, 'CatalogMeta should not bump when no new rows are inserted in locked mode');
});
