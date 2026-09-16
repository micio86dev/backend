<?php

declare(strict_types=1);

/**
 * gga review finding: `FrameworkCatalogSeeder`'s constructor switched from a
 * bare `env('FRAMEWORK_CATALOG_PATH')` call to `config('framework_catalog.
 * catalog_path')` — the right fix (a bare `env()` outside `config/` silently
 * returns `null` once config is cached), but shipped with nothing proving
 * the seeder actually reads from the configured path. A seeder silently
 * reading the WRONG directory produces a production database with no
 * competencies — the exact failure this config key exists to avoid.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

test('the seeder reads its source tree from framework_catalog.catalog_path when no explicit path is given', function (): void {
    $vendored = database_path('framework');
    $tempDir = sys_get_temp_dir().'/c3_catalog_path_override_'.uniqid();
    mkdir("{$tempDir}/bars", 0755, true);

    try {
        copy("{$vendored}/competencies.json", "{$tempDir}/competencies.json");
        foreach (glob("{$vendored}/bars/*.json") ?: [] as $barsFile) {
            copy($barsFile, "{$tempDir}/bars/".basename($barsFile));
        }

        // roles.json, DELIBERATELY mutated: a marker name that exists ONLY
        // in this temp copy — proving the seeder read THIS tree, not the
        // vendored one a bug could silently fall through to.
        $roles = json_decode(file_get_contents("{$vendored}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
        $roles['ICO']['name']['en'] = 'CATALOG_PATH_OVERRIDE_MARKER';
        file_put_contents("{$tempDir}/roles.json", json_encode($roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        config(['framework_catalog.catalog_path' => $tempDir]);

        // NO explicit constructor arguments — this is what exercises the
        // config-resolved default path rather than a caller-supplied override.
        (new FrameworkCatalogSeeder)->run();

        // Scoped to the baseline's OWN revision_id (gga review finding), not
        // a bare `where('code', 'ICO')` — the exact natural-key-by-code-alone
        // anti-pattern this whole schema exists to make impossible, per
        // FrameworkCatalogSeeder's own docblock.
        $baselineId = FrameworkCatalogRevision::where('is_baseline', true)->value('id');

        expect(Role::where('revision_id', $baselineId)->where('code', 'ICO')->firstOrFail()->getTranslation('name', 'en'))
            ->toBe('CATALOG_PATH_OVERRIDE_MARKER');
    } finally {
        array_map('unlink', glob("{$tempDir}/bars/*.json") ?: []);
        array_map('unlink', glob("{$tempDir}/*.json") ?: []);
        rmdir("{$tempDir}/bars");
        rmdir($tempDir);
    }
});
