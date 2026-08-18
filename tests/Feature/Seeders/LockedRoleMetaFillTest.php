<?php

declare(strict_types=1);

/**
 * RED — 0.3: Locked FrameworkVersion + fill-empty-only exception for
 * `Role.responsibilities` (C3 fix 5b).
 *
 * Today the per-call-site `$model->exists` gate suppresses
 * `setTranslation('responsibilities', ...)` for ANY pre-existing `Role` row
 * while a FrameworkVersion is locked — even when the stored value is an
 * empty string. This silently swallows SRX's authored responsibilities on a
 * locked production re-seed. The fix narrows this to a fill-empty-only
 * exception: `name` is never touched under lock, and a non-empty stored
 * value is never overwritten.
 *
 * Refs: design.md §5b; spec framework-catalog delta "SRX Role
 * Responsibilities — Authored Prerequisite", scenario "Locked
 * FrameworkVersion suppresses the SRX responsibilities update".
 */

use App\Models\FrameworkGap;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * Build a full fixture tree with one role's responsibilities forced to a
 * given value, so it can be mutated freely per test.
 *
 * @return array{string, string, string} [rolesFile, competenciesFile, barsDir]
 */
function buildLockedRoleMetaFixtures(string $tmpDir, string $roleCode, string $responsibilities): array
{
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    $roles = json_decode(file_get_contents("{$frameworkBase}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    $roles[$roleCode]['responsibilities'] = ['en' => $responsibilities];

    @mkdir("{$tmpDir}/bars", 0755, true);
    file_put_contents("{$tmpDir}/roles.json", json_encode($roles));
    file_put_contents("{$tmpDir}/competencies.json", file_get_contents("{$frameworkBase}/competencies.json"));

    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupLockedRoleMetaFixtures(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

function lockAFrameworkVersion(): void
{
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);
}

test('locked FV: empty stored responsibilities is filled from JSON (fill-empty-only)', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_locked_role_meta_'.uniqid();

    // First (unlocked) seed — SRX responsibilities forced empty, matching
    // pre-change production state.
    [$rolesFile, $competenciesFile, $barsDir] = buildLockedRoleMetaFixtures($tmpDir, 'SRX', '');
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $srx = Role::where('code', 'SRX')->firstOrFail();
    expect($srx->getTranslation('responsibilities', 'en'))->toBe('');

    lockAFrameworkVersion();

    // Author responsibilities in the JSON, then re-seed while locked.
    $roles = json_decode(file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);
    $roles['SRX']['responsibilities'] = ['en' => 'Now authored under lock.'];
    file_put_contents($rolesFile, json_encode($roles));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect($srx->fresh()->getTranslation('responsibilities', 'en'))->toBe('Now authored under lock.');

    cleanupLockedRoleMetaFixtures($tmpDir);
});

test('locked FV: non-empty stored responsibilities is never overwritten', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_locked_role_meta_nonempty_'.uniqid();

    [$rolesFile, $competenciesFile, $barsDir] = buildLockedRoleMetaFixtures($tmpDir, 'ICO', 'Original ICO responsibilities.');
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    expect($ico->getTranslation('responsibilities', 'en'))->toBe('Original ICO responsibilities.');

    lockAFrameworkVersion();

    $roles = json_decode(file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);
    $roles['ICO']['responsibilities'] = ['en' => 'Attempted overwrite.'];
    file_put_contents($rolesFile, json_encode($roles));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect($ico->fresh()->getTranslation('responsibilities', 'en'))->toBe('Original ICO responsibilities.');

    cleanupLockedRoleMetaFixtures($tmpDir);
});

test('locked FV: role name is never touched even when responsibilities is filled', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_locked_role_meta_name_'.uniqid();

    [$rolesFile, $competenciesFile, $barsDir] = buildLockedRoleMetaFixtures($tmpDir, 'SRX', '');
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $srx = Role::where('code', 'SRX')->firstOrFail();
    $originalName = $srx->getTranslation('name', 'en');

    lockAFrameworkVersion();

    $roles = json_decode(file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);
    $roles['SRX']['responsibilities'] = ['en' => 'Filled under lock.'];
    $roles['SRX']['name'] = ['en' => 'Attempted name overwrite'];
    file_put_contents($rolesFile, json_encode($roles));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect($srx->fresh()->getTranslation('name', 'en'))->toBe($originalName);

    cleanupLockedRoleMetaFixtures($tmpDir);
});

test('locked FV: filling empty responsibilities emits locked_fill_empty_role_meta gap', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_locked_role_meta_signal_'.uniqid();

    [$rolesFile, $competenciesFile, $barsDir] = buildLockedRoleMetaFixtures($tmpDir, 'SRX', '');
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    lockAFrameworkVersion();

    $roles = json_decode(file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);
    $roles['SRX']['responsibilities'] = ['en' => 'Filled under lock, with signal.'];
    file_put_contents($rolesFile, json_encode($roles));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect(
        FrameworkGap::where('kind', 'locked_fill_empty_role_meta')
            ->where('role_code', 'SRX')
            ->exists()
    )->toBeTrue();

    cleanupLockedRoleMetaFixtures($tmpDir);
});
