<?php

declare(strict_types=1);

/**
 * RED — Phase 3 (`framework-catalog-it-translations`, design D4): the seeder's
 * structural-change predicate widens from "a genuinely NEW row was inserted"
 * to "a row was inserted OR an EXISTING row actually changed"
 * (`$model->wasRecentlyCreated || $model->wasChanged()`).
 *
 * Today `CatalogMeta::bump()` fires only when `wasRecentlyCreated` is true
 * anywhere in the run. Editing an EXISTING English anchor in the source JSON
 * and re-seeding therefore does NOT bump `revision` today — even though the
 * catalogue content genuinely changed and `BarsIndicatorResource` (and any
 * consumer caching on `revision`) would now serve a different payload for an
 * unmoved cache key. This is the "named side effect" design D4 calls out: a
 * latent stale-cache bug, fixed in passing, by this widened predicate.
 *
 * This is its own separable slice, independently revertible from the locale
 * content that will later reuse the same predicate (Phase 4).
 *
 * Refs: design.md D4 ("CatalogMeta::bump() — yes, a new locale is
 * structural"), tasks.md Phase 3.
 */

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * @return array{string, string, string} [rolesFile, competenciesFile, barsDir]
 */
function buildRevisionBumpFixtureTree(string $tmpDir): array
{
    $sourceBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    @mkdir("{$tmpDir}/bars", 0755, true);
    copy("{$sourceBase}/roles.json", "{$tmpDir}/roles.json");
    copy("{$sourceBase}/competencies.json", "{$tmpDir}/competencies.json");
    foreach (glob("{$sourceBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupRevisionBumpFixtureTree(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

test('re-seeding a change to ONLY an existing English anchor bumps the catalog revision', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_revision_bump_edit_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildRevisionBumpFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $baselineRevision = CatalogMeta::first()->revision;
    expect($baselineRevision)->toBeGreaterThan(0);

    $icoRole = Role::where('code', 'ICO')->firstOrFail();
    $prsCompetency = Competency::where('code', 'PRS')->firstOrFail();
    $indicator = BarsIndicator::where('role_id', $icoRole->id)
        ->where('competency_id', $prsCompetency->id)
        ->where('position', 0)
        ->firstOrFail();
    $originalAnchor5 = $indicator->getTranslation('anchor_5', 'en');

    // Edit ONLY the anchor_5 EN text for ICO/PRS position 0 — no new row anywhere.
    $icoBars = json_decode(file_get_contents("{$barsDir}/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBars['PRS'][0]['scale']['5']['en'] = 'EDITED anchor text — English mutation only, no new row.';
    file_put_contents("{$barsDir}/ICO.json", json_encode($icoBars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect($indicator->fresh()->getTranslation('anchor_5', 'en'))
        ->toBe('EDITED anchor text — English mutation only, no new row.')
        ->and($indicator->fresh()->getTranslation('anchor_5', 'en'))->not->toBe($originalAnchor5);

    $revisionAfterEdit = CatalogMeta::first()->fresh()->revision;

    // THE ASSERTION THAT FAILS ON CURRENT CODE: no row was created (only an
    // existing row's translation column changed), so today's
    // `wasRecentlyCreated`-only predicate leaves `$structuralChange` false and
    // `bump()` is never called. This MUST now bump.
    expect($revisionAfterEdit)->toBeGreaterThan($baselineRevision);

    cleanupRevisionBumpFixtureTree($tmpDir);
});

test('a true no-op re-seed (no source change at all) does NOT bump the catalog revision', function (): void {
    $tmpDir = sys_get_temp_dir().'/c_it_revision_bump_noop_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildRevisionBumpFixtureTree($tmpDir);

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();
    $baselineRevision = CatalogMeta::first()->revision;
    expect($baselineRevision)->toBeGreaterThan(0);

    // Re-seed with byte-identical source JSON — nothing changed anywhere.
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $revisionAfterNoop = CatalogMeta::first()->fresh()->revision;

    expect($revisionAfterNoop)->toBe($baselineRevision);

    cleanupRevisionBumpFixtureTree($tmpDir);
});
