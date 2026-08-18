<?php

declare(strict_types=1);

/**
 * Phase 4 (`framework-catalog-it-translations`, design D5, tasks.md 4.5-4.6):
 * per-pair `missing_translation` gap resolution — evaluated at role×
 * competency PAIR granularity (12 strings: 3 indicators × {text, anchor_5,
 * anchor_3, anchor_1}). 11 of 12 counts as 0, mirroring the scoring-engine's
 * own per-competency hard-fail unit. The GLOBAL row (role_code=null,
 * competency_code=null) resolves only once every anchored pair is complete;
 * an orphan sweep resolves a per-pair row whose pair is no longer declared.
 *
 * Refs: design.md D5; spec framework-catalog delta "Gap Row Reconciliation
 * on Seeded Content", scenarios "missing_translation resolves once a full
 * role×competency pair's 12 strings are translated" / "... stays pending
 * when 11 of 12 strings are translated".
 */

use App\Models\FrameworkGap;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * Recursively strip the `it` key out of every nested locale-map in a
 * decoded JSON structure, leaving `en` (and any other locale) untouched.
 */
function stripItLocaleForLocaleGapResolution(array $data): array
{
    foreach ($data as $key => $value) {
        if ($key === 'it') {
            unset($data[$key]);

            continue;
        }
        if (is_array($value)) {
            $data[$key] = stripItLocaleForLocaleGapResolution($value);
        }
    }

    return $data;
}

/**
 * Build a full fixture tree (roles.json, competencies.json, bars/) copied
 * from the real wrapper catalog, with every `it` translation stripped back
 * out — a manufactured, self-contained "no IT authored yet" precondition
 * for all 83 role×competency pairs, decoupled from how much of the real
 * catalogue happens to be translated at any given time (the same pattern
 * tests/Feature/Seeders/GapResolutionTest.php uses for competency_no_bars).
 *
 * @return array{string, string, string} [rolesFile, competenciesFile, barsDir]
 */
function buildLocaleGapResolutionFixtureTree(string $tmpDir): array
{
    $sourceBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    @mkdir("{$tmpDir}/bars", 0755, true);
    copy("{$sourceBase}/roles.json", "{$tmpDir}/roles.json");
    copy("{$sourceBase}/competencies.json", "{$tmpDir}/competencies.json");
    foreach (glob("{$sourceBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    $roles = json_decode(file_get_contents("{$tmpDir}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("{$tmpDir}/roles.json", json_encode(stripItLocaleForLocaleGapResolution($roles)));

    $competencies = json_decode(file_get_contents("{$tmpDir}/competencies.json"), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("{$tmpDir}/competencies.json", json_encode(stripItLocaleForLocaleGapResolution($competencies)));

    foreach (glob("{$tmpDir}/bars/*.json") as $barsFile) {
        $bars = json_decode(file_get_contents($barsFile), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($barsFile, json_encode(stripItLocaleForLocaleGapResolution($bars)));
    }

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupLocaleGapResolutionFixtureTree(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

/** Fill all 12 IT strings for ICO×PRS (3 indicators × 4 fields) in the fixture bars file. */
function fillFullItTranslationForIcoPrs(string $barsDir): void
{
    $icoBars = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    foreach ($icoBars['PRS'] as $i => $entry) {
        $icoBars['PRS'][$i]['indicator']['it'] = "Indicatore IT {$i}";
        $icoBars['PRS'][$i]['scale']['5']['it'] = "Ancora 5 IT {$i}";
        $icoBars['PRS'][$i]['scale']['3']['it'] = "Ancora 3 IT {$i}";
        $icoBars['PRS'][$i]['scale']['1']['it'] = "Ancora 1 IT {$i}";
    }
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBars));
}

test('missing_translation resolves once a full role×competency pair (12 strings) is translated', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_locale_gap_full_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLocaleGapResolutionFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect(
        FrameworkGap::where('kind', 'missing_translation')
            ->where('role_code', 'ICO')->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')->exists()
    )->toBeTrue('gap must start pending — no IT strings exist yet');

    fillFullItTranslationForIcoPrs($barsDir);
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect(
        FrameworkGap::where('kind', 'missing_translation')
            ->where('role_code', 'ICO')->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')->exists()
    )->toBeFalse('gap must resolve once all 12 strings are translated');

    // A sibling pair with zero IT strings remains pending.
    expect(
        FrameworkGap::where('kind', 'missing_translation')
            ->where('role_code', 'ICO')->where('competency_code', 'STG')
            ->where('status', 'pending_authoring')->exists()
    )->toBeTrue('a sibling pair still missing even one of its 12 strings must remain pending');

    cleanupLocaleGapResolutionFixtureTree($tmpDir);
});

test('missing_translation stays pending when 11 of 12 strings are translated', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_locale_gap_eleven_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLocaleGapResolutionFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    fillFullItTranslationForIcoPrs($barsDir);

    // Remove exactly ONE of the 12 strings (leave 11 of 12).
    $icoBars = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    unset($icoBars['PRS'][0]['scale']['1']['it']);
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBars));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect(
        FrameworkGap::where('kind', 'missing_translation')
            ->where('role_code', 'ICO')->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')->exists()
    )->toBeTrue('11 of 12 strings must be treated as zero (mirrors the scoring-engine per-competency hard-fail unit)');

    cleanupLocaleGapResolutionFixtureTree($tmpDir);
});

test('missing_translation global row resolves only once every anchored pair is fully translated', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_locale_gap_global_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLocaleGapResolutionFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $globalGap = FrameworkGap::where('kind', 'missing_translation')
        ->whereNull('role_code')->whereNull('competency_code')->firstOrFail();
    expect($globalGap->status)->toBe('pending_authoring')
        ->and($globalGap->note)->toContain('pending');

    fillFullItTranslationForIcoPrs($barsDir);
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $globalGap = $globalGap->fresh();
    expect($globalGap->status)->toBe('pending_authoring', 'only ONE of 83 pairs is translated — the global row must still be pending');
    expect($globalGap->note)->toContain('82');

    cleanupLocaleGapResolutionFixtureTree($tmpDir);
});

test('missing_translation per-pair gap resolves (orphan sweep) once its pair is no longer assigned to the role', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_locale_gap_orphan_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLocaleGapResolutionFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect(
        FrameworkGap::where('kind', 'missing_translation')
            ->where('role_code', 'ICO')->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')->exists()
    )->toBeTrue();

    // Unassign PRS from ICO in roles.json — the gap is now moot.
    $roles = json_decode(file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);
    $roles['ICO']['competencies'] = array_values(array_filter($roles['ICO']['competencies'], fn ($c) => $c !== 'PRS'));
    file_put_contents($rolesFile, json_encode($roles));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect(
        FrameworkGap::where('kind', 'missing_translation')
            ->where('role_code', 'ICO')->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')->exists()
    )->toBeFalse('an orphaned per-pair gap (pair no longer assigned) must resolve, mirroring competency_no_bars\'s own orphan sweep');

    cleanupLocaleGapResolutionFixtureTree($tmpDir);
});
