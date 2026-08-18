<?php

declare(strict_types=1);

/**
 * RED — Phase 4 (`framework-catalog-it-translations`, design D5, tasks.md
 * 4.7-4.8): `php artisan framework:forget-locale it [--dry-run] [--force]`
 * is the ONLY rollback for a locale added via `setTranslation` — proved by
 * `tests/Feature/Seeders/TranslationSurvivalReseedTest.php` that a bare
 * re-seed after removing `it` from the source JSON does NOT remove it from
 * the database (merge semantics). This command must: remove `it`, leave
 * `en`; refuse while any FrameworkVersion is locked; require `--force`
 * outside the local environment (the test environment is NOT `local`, so
 * every scenario here that does not pass `--force` proves the refusal for
 * real, not by assumption); and bump `catalog_meta.revision`.
 */

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * A minimal fixture tree with real `it` content on ICO/PRS (indicator +
 * all 3 anchors) and on role/competency metadata, seeded unlocked so the
 * database genuinely carries `it` translations to forget.
 *
 * @return array{string, string, string} [rolesFile, competenciesFile, barsDir]
 */
function buildForgetLocaleFixtureTree(string $tmpDir): array
{
    $sourceBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    $roles = json_decode(file_get_contents("{$sourceBase}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    $competencies = json_decode(file_get_contents("{$sourceBase}/competencies.json"), true, 512, JSON_THROW_ON_ERROR);

    $roles['ICO']['name']['it'] = 'Collaboratore Individuale';
    $competencies['PRS']['name']['it'] = 'Risoluzione dei Problemi';

    @mkdir("{$tmpDir}/bars", 0755, true);
    file_put_contents("{$tmpDir}/roles.json", json_encode($roles));
    file_put_contents("{$tmpDir}/competencies.json", json_encode($competencies));

    foreach (glob("{$sourceBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    $icoBars = json_decode(file_get_contents("{$tmpDir}/bars/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBars['PRS'][0]['indicator']['it'] = 'Individuare i sintomi che indicano un problema';
    $icoBars['PRS'][0]['scale']['5']['it'] = 'Testo ancora 5 in italiano';
    $icoBars['PRS'][0]['scale']['3']['it'] = 'Testo ancora 3 in italiano';
    $icoBars['PRS'][0]['scale']['1']['it'] = 'Testo ancora 1 in italiano';
    file_put_contents("{$tmpDir}/bars/ICO.json", json_encode($icoBars));

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupForgetLocaleFixtureTree(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

test('forget-locale removes it and leaves en intact, across roles/competencies/indicators', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_forget_locale_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildForgetLocaleFixtureTree($tmpDir);
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $prs = Competency::where('code', 'PRS')->firstOrFail();
    $indicator = BarsIndicator::where('role_id', $ico->id)->where('competency_id', $prs->id)->where('position', 0)->firstOrFail();

    expect($ico->hasTranslation('name', 'it'))->toBeTrue()
        ->and($prs->hasTranslation('name', 'it'))->toBeTrue()
        ->and($indicator->hasTranslation('text', 'it'))->toBeTrue()
        ->and($indicator->hasTranslation('anchor_5', 'it'))->toBeTrue()
        ->and($indicator->hasTranslation('anchor_3', 'it'))->toBeTrue()
        ->and($indicator->hasTranslation('anchor_1', 'it'))->toBeTrue();

    $icoNameEn = $ico->getTranslation('name', 'en');
    $prsNameEn = $prs->getTranslation('name', 'en');
    $indicatorTextEn = $indicator->getTranslation('text', 'en');

    $this->artisan('framework:forget-locale', ['locale' => 'it', '--force' => true])
        ->assertExitCode(0);

    $ico = $ico->fresh();
    $prs = $prs->fresh();
    $indicator = $indicator->fresh();

    expect($ico->hasTranslation('name', 'it'))->toBeFalse()
        ->and($prs->hasTranslation('name', 'it'))->toBeFalse()
        ->and($indicator->hasTranslation('text', 'it'))->toBeFalse()
        ->and($indicator->hasTranslation('anchor_5', 'it'))->toBeFalse()
        ->and($indicator->hasTranslation('anchor_3', 'it'))->toBeFalse()
        ->and($indicator->hasTranslation('anchor_1', 'it'))->toBeFalse();

    // `en` is completely untouched.
    expect($ico->getTranslation('name', 'en'))->toBe($icoNameEn)
        ->and($prs->getTranslation('name', 'en'))->toBe($prsNameEn)
        ->and($indicator->getTranslation('text', 'en'))->toBe($indicatorTextEn);

    cleanupForgetLocaleFixtureTree($tmpDir);
});

test('forget-locale refuses to run while any FrameworkVersion is locked', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_forget_locale_locked_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildForgetLocaleFixtureTree($tmpDir);
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    expect($ico->hasTranslation('name', 'it'))->toBeTrue();

    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);

    $this->artisan('framework:forget-locale', ['locale' => 'it', '--force' => true])
        ->assertExitCode(1);

    // Nothing was removed — the refusal happened before any write.
    expect($ico->fresh()->hasTranslation('name', 'it'))->toBeTrue();

    cleanupForgetLocaleFixtureTree($tmpDir);
});

test('forget-locale requires --force outside the local environment (test env is not local)', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_forget_locale_noforce_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildForgetLocaleFixtureTree($tmpDir);
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    expect($ico->hasTranslation('name', 'it'))->toBeTrue();

    // No --force passed, and the test environment is "testing", not "local".
    $this->artisan('framework:forget-locale', ['locale' => 'it'])
        ->assertExitCode(1);

    expect($ico->fresh()->hasTranslation('name', 'it'))->toBeTrue('nothing must be removed when the command refuses to run');

    cleanupForgetLocaleFixtureTree($tmpDir);
});

test('forget-locale --dry-run reports counts without writing anything', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_forget_locale_dryrun_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildForgetLocaleFixtureTree($tmpDir);
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();

    $this->artisan('framework:forget-locale', ['locale' => 'it', '--dry-run' => true])
        ->assertExitCode(0);

    // Dry-run never requires --force and never writes.
    expect($ico->fresh()->hasTranslation('name', 'it'))->toBeTrue('dry-run must not remove anything');

    cleanupForgetLocaleFixtureTree($tmpDir);
});

test('forget-locale refuses to forget "en"', function (): void {
    $this->artisan('framework:forget-locale', ['locale' => 'en', '--force' => true])
        ->assertExitCode(1);
});

test('forget-locale bumps the catalog revision when it actually removes something', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_forget_locale_bump_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildForgetLocaleFixtureTree($tmpDir);
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $before = CatalogMeta::first()->revision;

    $this->artisan('framework:forget-locale', ['locale' => 'it', '--force' => true])
        ->assertExitCode(0);

    $after = CatalogMeta::first()->fresh()->revision;
    expect($after)->toBeGreaterThan($before);

    cleanupForgetLocaleFixtureTree($tmpDir);
});
