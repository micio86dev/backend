<?php

declare(strict_types=1);

/**
 * Phase 4 (`framework-catalog-it-translations`, design D4, tasks.md 4.1-4.2):
 * the seeder writes EVERY locale present in a field's nested source map —
 * not `en` only. `hasTranslation('anchor_5', 'it')` must be true from an IT
 * fixture; `en` must be unchanged; and a genuinely NEW locale must bump the
 * catalog revision (depends on Phase 3's widened predicate).
 *
 * Refs: design.md D4; spec framework-catalog delta "Translatable Content
 * Columns", scenarios "Seeder writes an IT translation from a
 * nested-locale source field" / "Seeder leaves IT untouched when the
 * source has no IT value".
 */

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * Recursively strip the `it` key out of every nested locale-map in a
 * decoded JSON structure, leaving `en` (and any other locale) untouched.
 */
function stripItLocaleForItLocaleSeed(array $data): array
{
    foreach ($data as $key => $value) {
        if ($key === 'it') {
            unset($data[$key]);

            continue;
        }
        if (is_array($value)) {
            $data[$key] = stripItLocaleForItLocaleSeed($value);
        }
    }

    return $data;
}

/**
 * Build a full fixture tree (roles.json, competencies.json, bars/) copied
 * from the real wrapper catalog, with every `it` translation stripped back
 * out — a manufactured, self-contained "no IT authored yet" precondition,
 * decoupled from how much of the real catalogue happens to be translated
 * at any given time (the same pattern
 * tests/Feature/Seeders/GapResolutionTest.php uses for competency_no_bars).
 *
 * @return array{string, string, string} [rolesFile, competenciesFile, barsDir]
 */
function buildItLocaleSeedFixtureTree(string $tmpDir): array
{
    $sourceBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    @mkdir("{$tmpDir}/bars", 0755, true);
    copy("{$sourceBase}/roles.json", "{$tmpDir}/roles.json");
    copy("{$sourceBase}/competencies.json", "{$tmpDir}/competencies.json");
    foreach (glob("{$sourceBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    $roles = json_decode(file_get_contents("{$tmpDir}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("{$tmpDir}/roles.json", json_encode(stripItLocaleForItLocaleSeed($roles)));

    $competencies = json_decode(file_get_contents("{$tmpDir}/competencies.json"), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("{$tmpDir}/competencies.json", json_encode(stripItLocaleForItLocaleSeed($competencies)));

    foreach (glob("{$tmpDir}/bars/*.json") as $barsFile) {
        $bars = json_decode(file_get_contents($barsFile), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($barsFile, json_encode(stripItLocaleForItLocaleSeed($bars)));
    }

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupItLocaleSeedFixtureTree(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

test('seeder writes an IT translation from a nested-locale source field; en unchanged; revision bumps', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_locale_seed_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildItLocaleSeedFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $prs = Competency::where('code', 'PRS')->firstOrFail();
    $indicator = BarsIndicator::where('role_id', $ico->id)->where('competency_id', $prs->id)->where('position', 0)->firstOrFail();
    $originalAnchor5En = $indicator->getTranslation('anchor_5', 'en');
    $revisionBefore = CatalogMeta::first()->revision;

    expect($indicator->hasTranslation('anchor_5', 'it'))->toBeFalse('fixture precondition: no IT exists yet');

    // Author an IT fixture value for anchor_5 only.
    $icoBars = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBars['PRS'][0]['scale']['5']['it'] = 'Testo ancora 5 in italiano, dal fixture di test.';
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBars));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $indicator = $indicator->fresh();

    expect($indicator->hasTranslation('anchor_5', 'it'))->toBeTrue()
        ->and($indicator->getTranslation('anchor_5', 'it'))->toBe('Testo ancora 5 in italiano, dal fixture di test.')
        ->and($indicator->getTranslation('anchor_5', 'en'))->toBe($originalAnchor5En);

    $revisionAfter = CatalogMeta::first()->fresh()->revision;
    expect($revisionAfter)->toBeGreaterThan($revisionBefore, 'a new locale is structural (design D4) and must bump the revision');

    cleanupItLocaleSeedFixtureTree($tmpDir);
});

test('seeder leaves IT untouched when the source has no IT value (fixture-manufactured gap)', function (): void {
    // History: this gap used to be real and un-manufactured — no IT existed
    // anywhere in the catalogue. Since `framework-catalog-it-translations`
    // authored all 83 pairs, the absence is manufactured in the fixture
    // copy (buildItLocaleSeedFixtureTree strips `it`), the same as every
    // other test in this file. The assertion this test exists to protect —
    // hasTranslation() vs getTranslation() semantics, see below — is
    // unchanged by that.
    $tmpDir = sys_get_temp_dir().'/c_it_locale_seed_absent_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildItLocaleSeedFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $ico = Role::where('code', 'ICO')->firstOrFail();
    $prs = Competency::where('code', 'PRS')->firstOrFail();
    $indicator = BarsIndicator::where('role_id', $ico->id)->where('competency_id', $prs->id)->where('position', 0)->firstOrFail();

    // hasTranslation(), NOT getTranslation(), is the real-presence check — the
    // framework-catalog spec itself requires this: getTranslation('anchor_1','it')
    // would return the EN FALLBACK text (config/translatable.php fallback_locale),
    // which is correct app behavior but must never be mistaken for "IT exists".
    expect($indicator->hasTranslation('anchor_1', 'it'))->toBeFalse();

    cleanupItLocaleSeedFixtureTree($tmpDir);
});

test('seeder writes IT for competency name/definition and role name/responsibilities the same way', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_locale_seed_meta_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildItLocaleSeedFixtureTree($tmpDir);

    $competencies = json_decode(file_get_contents($competenciesFile), true, 512, JSON_THROW_ON_ERROR);
    $competencies['PRS']['name']['it'] = 'Risoluzione dei Problemi';
    file_put_contents($competenciesFile, json_encode($competencies));

    $roles = json_decode(file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);
    $roles['ICO']['name']['it'] = 'Collaboratore Individuale';
    file_put_contents($rolesFile, json_encode($roles));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $prs = Competency::where('code', 'PRS')->firstOrFail();
    $ico = Role::where('code', 'ICO')->firstOrFail();

    expect($prs->getTranslation('name', 'it'))->toBe('Risoluzione dei Problemi')
        ->and($ico->getTranslation('name', 'it'))->toBe('Collaboratore Individuale');

    cleanupItLocaleSeedFixtureTree($tmpDir);
});
