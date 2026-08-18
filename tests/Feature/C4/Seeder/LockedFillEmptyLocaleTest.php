<?php

declare(strict_types=1);

/**
 * Phase 4 (`framework-catalog-it-translations`, design D4, tasks.md 4.3-4.4):
 * the fill-empty-locale exception under a locked FrameworkVersion — the
 * PRIMARY path this proposal exists to fix, not an edge case.
 * `ProjectController::store` locks a FrameworkVersion the moment ANY tenant
 * creates a project, so production carries zero locked FVs only until the
 * first real signup — after that, `setTranslation` on an EXISTING catalog
 * row is suppressed by the pre-existing per-call-site `$model->exists` gate.
 * Without this exception, a locked catalogue could NEVER become
 * translatable at all.
 *
 * Under lock: an EMPTY `it` slot MAY be filled; a NON-empty `it` value is
 * NEVER overwritten; `en` is NEVER touched; and the fill emits an explicit,
 * inspectable signal (`locked_fill_empty_locale` FrameworkGap + Log::warning)
 * — mirroring the existing `Role.responsibilities` fill-empty-only
 * precedent (`locked_fill_empty_role_meta`), extended to every translatable
 * field and every non-en locale.
 *
 * Refs: design.md D4 ("Locked-FV behaviour — the fill-empty-locale
 * exception"); spec framework-catalog delta "Idempotent Catalog Seeder",
 * scenario "Locked FV suppresses new IT translation — explicit signal, not
 * a silent no-op" (the INVERSE case — no existing IT — is this file's
 * positive counterpart: an ABSENT IT value, not merely a suppressed one,
 * IS filled).
 */

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\FrameworkGap;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * Recursively strip the `it` key out of every nested locale-map in a
 * decoded JSON structure, leaving `en` (and any other locale) untouched.
 */
function stripItLocaleForLockedFillEmptyLocale(array $data): array
{
    foreach ($data as $key => $value) {
        if ($key === 'it') {
            unset($data[$key]);

            continue;
        }
        if (is_array($value)) {
            $data[$key] = stripItLocaleForLockedFillEmptyLocale($value);
        }
    }

    return $data;
}

/**
 * Build a full fixture tree (roles.json, competencies.json, bars/) copied
 * from the real wrapper catalog, with every `it` translation stripped back
 * out — a manufactured, self-contained "empty it slot" precondition,
 * decoupled from how much of the real catalogue happens to be translated
 * at any given time (the same pattern
 * tests/Feature/Seeders/GapResolutionTest.php uses for competency_no_bars).
 *
 * @return array{string, string, string} [rolesFile, competenciesFile, barsDir]
 */
function buildLockedFillEmptyLocaleFixtureTree(string $tmpDir): array
{
    $sourceBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    @mkdir("{$tmpDir}/bars", 0755, true);
    copy("{$sourceBase}/roles.json", "{$tmpDir}/roles.json");
    copy("{$sourceBase}/competencies.json", "{$tmpDir}/competencies.json");
    foreach (glob("{$sourceBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    $roles = json_decode(file_get_contents("{$tmpDir}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("{$tmpDir}/roles.json", json_encode(stripItLocaleForLockedFillEmptyLocale($roles)));

    $competencies = json_decode(file_get_contents("{$tmpDir}/competencies.json"), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("{$tmpDir}/competencies.json", json_encode(stripItLocaleForLockedFillEmptyLocale($competencies)));

    foreach (glob("{$tmpDir}/bars/*.json") as $barsFile) {
        $bars = json_decode(file_get_contents($barsFile), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($barsFile, json_encode(stripItLocaleForLockedFillEmptyLocale($bars)));
    }

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupLockedFillEmptyLocaleFixtureTree(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

function lockAFrameworkVersionForLocaleFillTest(): void
{
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    FrameworkVersion::factory()->locked()->create(['organization_id' => $org->id]);
}

test('locked FV: an EMPTY it slot on an existing BarsIndicator is filled from the source JSON', function (): void {
    $tmpDir = sys_get_temp_dir().'/c4_locale_fill_indicator_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLockedFillEmptyLocaleFixtureTree($tmpDir);

    // Unlocked baseline seed — EN only, no IT anywhere yet.
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $prs = Competency::where('code', 'PRS')->firstOrFail();
    $indicator = BarsIndicator::where('role_id', $ico->id)->where('competency_id', $prs->id)->where('position', 0)->firstOrFail();
    $originalTextEn = $indicator->getTranslation('text', 'en');
    expect($indicator->hasTranslation('text', 'it'))->toBeFalse();

    lockAFrameworkVersionForLocaleFillTest();

    // Author IT for this one indicator's text field, then re-seed while locked.
    $icoBars = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBars['PRS'][0]['indicator']['it'] = 'Individuare i sintomi che indicano un problema, autorato sotto lock.';
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBars));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $indicator = $indicator->fresh();
    expect($indicator->hasTranslation('text', 'it'))->toBeTrue()
        ->and($indicator->getTranslation('text', 'it'))->toBe('Individuare i sintomi che indicano un problema, autorato sotto lock.')
        ->and($indicator->getTranslation('text', 'en'))->toBe($originalTextEn, 'en must never be touched under lock');

    cleanupLockedFillEmptyLocaleFixtureTree($tmpDir);
});

test('locked FV: a NON-empty it value is never overwritten', function (): void {
    $tmpDir = sys_get_temp_dir().'/c4_locale_fill_nonempty_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLockedFillEmptyLocaleFixtureTree($tmpDir);

    // Author IT BEFORE the first (unlocked) seed, so it lands in the DB for real.
    $icoBars = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBars['PRS'][0]['indicator']['it'] = 'Testo italiano originale.';
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBars));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $prs = Competency::where('code', 'PRS')->firstOrFail();
    $indicator = BarsIndicator::where('role_id', $ico->id)->where('competency_id', $prs->id)->where('position', 0)->firstOrFail();
    expect($indicator->getTranslation('text', 'it'))->toBe('Testo italiano originale.');

    lockAFrameworkVersionForLocaleFillTest();

    // Attempt to overwrite the IT value while locked.
    $icoBarsAttempt = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBarsAttempt['PRS'][0]['indicator']['it'] = 'TENTATIVO DI SOVRASCRITTURA.';
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBarsAttempt));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect($indicator->fresh()->getTranslation('text', 'it'))->toBe('Testo italiano originale.', 'a non-empty IT value must never be overwritten under lock');

    cleanupLockedFillEmptyLocaleFixtureTree($tmpDir);
});

test('locked FV: filling an empty it locale emits the locked_fill_empty_locale signal', function (): void {
    $tmpDir = sys_get_temp_dir().'/c4_locale_fill_signal_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLockedFillEmptyLocaleFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    lockAFrameworkVersionForLocaleFillTest();

    $icoBars = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBars['PRS'][0]['indicator']['it'] = 'Riempito sotto lock, con segnale.';
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBars));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect(
        FrameworkGap::where('kind', 'locked_fill_empty_locale')
            ->where('role_code', 'ICO')
            ->where('competency_code', 'PRS')
            ->exists()
    )->toBeTrue();

    cleanupLockedFillEmptyLocaleFixtureTree($tmpDir);
});

test('locked FV: filling an empty it locale on an indicator field ALSO bumps the catalog revision', function (): void {
    $tmpDir = sys_get_temp_dir().'/c4_locale_fill_bump_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLockedFillEmptyLocaleFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    lockAFrameworkVersionForLocaleFillTest();

    $revisionBefore = CatalogMeta::first()->revision;

    $icoBars = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBars['PRS'][0]['indicator']['it'] = 'Riempito sotto lock — deve incrementare la revisione.';
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBars));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $revisionAfter = CatalogMeta::first()->fresh()->revision;
    expect($revisionAfter)->toBeGreaterThan($revisionBefore, 'translation_gap flipping false changes the response body, so the cache-busting revision must move (design D4)');

    cleanupLockedFillEmptyLocaleFixtureTree($tmpDir);
});
