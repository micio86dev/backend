<?php

/**
 * RED — 11.5: Locale fallback in API (C3).
 *
 * Seeds a fixture with ICO/PRS's IT translations stripped; asserts
 * ?locale=it returns EN fallback + translation_gap=true. Also asserts
 * Accept-Language header is honoured.
 *
 * Refs spec: "Locale-aware response falls back to EN when IT is absent".
 *
 * History: ICO/PRS carried no IT translations in the real catalogue until
 * `framework-catalog-it-translations` authored all 83 role×competency
 * pairs. Post-authoring, this test's precondition — an indicator missing
 * its IT translation — is manufactured in a FIXTURE COPY only, the same
 * pattern tests/Feature/Seeders/GapResolutionTest.php uses for
 * competency_no_bars.
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * Build a full fixture tree (roles.json, competencies.json, bars/) copied
 * from the real wrapper catalog, with ICO's PRS indicators stripped of
 * their `it` translations — a manufactured, self-contained
 * translation-gap pair, decoupled from which pairs (if any) happen to be
 * untranslated in the real tree.
 *
 * @return array{0: string, 1: string, 2: string} [rolesFile, competenciesFile, barsDir]
 */
function buildLocaleFallbackFixture(string $tmpDir): array
{
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';

    @mkdir("{$tmpDir}/bars", 0755, true);
    copy("{$frameworkBase}/roles.json", "{$tmpDir}/roles.json");
    copy("{$frameworkBase}/competencies.json", "{$tmpDir}/competencies.json");
    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    $icoBars = json_decode(file_get_contents("{$tmpDir}/bars/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    foreach ($icoBars['PRS'] as $i => $entry) {
        unset($icoBars['PRS'][$i]['indicator']['it']);
        unset($icoBars['PRS'][$i]['scale']['5']['it']);
        unset($icoBars['PRS'][$i]['scale']['3']['it']);
        unset($icoBars['PRS'][$i]['scale']['1']['it']);
    }
    file_put_contents("{$tmpDir}/bars/ICO.json", json_encode($icoBars));

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupLocaleFallbackFixture(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

test('locale=it returns EN fallback text and translation_gap=true when IT is absent', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_locale_fallback_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLocaleFallbackFixture($tmpDir);

    // Seed against the fixture only — the manufactured gap, not the real catalogue.
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    // ICO/PRS indicators are stripped of IT in the fixture (manufactured gap).
    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies/PRS/indicators?locale=it');

    $response->assertOk();

    $firstIndicator = $response->json('data.0');

    // EN fallback text must be non-null (not empty)
    expect($firstIndicator['text'])->not->toBeNull()->not->toBeEmpty();

    // translation_gap must be true (IT not authored, by construction of the fixture)
    expect($firstIndicator['translation_gap'])->toBeTrue();

    cleanupLocaleFallbackFixture($tmpDir);
});

test('Accept-Language: it header selects IT locale (returns EN fallback since no IT exists)', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_locale_fallback_header_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildLocaleFallbackFixture($tmpDir);

    // Seed against the fixture only — the manufactured gap, not the real catalogue.
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    // Use Accept-Language header instead of ?locale= param
    $response = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'it'])
        ->getJson('/api/framework/roles/ICO/competencies/PRS/indicators');

    $response->assertOk();

    $firstIndicator = $response->json('data.0');

    // Should still return EN fallback text (IT not authored in the fixture)
    expect($firstIndicator['text'])->not->toBeNull()->not->toBeEmpty();

    // translation_gap must be true
    expect($firstIndicator['translation_gap'])->toBeTrue();

    cleanupLocaleFallbackFixture($tmpDir);
});
