<?php

/**
 * RED — 11.8: bars_available flag in competency list (C3).
 *
 * ICO/COM → bars_available=true (real catalogue); a manufactured
 * fixture pair → bars_available=false. Also guards against N+1 query
 * regression.
 *
 * Refs spec: "bars_available flag reflects BARS coverage (fixture example
 * for the false case)".
 *
 * History: the false-case example was FLL/PRS until
 * bars-catalogue-completion Phase 4 anchored it, then SRX/STG until Phase 6
 * anchored all of SRX. Post-completion, all 83 declared role×competency
 * pairs have anchors, so `bars_available=false` has no real-catalogue
 * occurrence — task 7.4 moves this test onto a fixture that manufactures
 * its own incomplete competency, the same pattern
 * tests/Feature/Seeders/GapResolutionTest.php already uses for
 * competency_no_bars.
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Build a full fixture tree (roles.json, competencies.json, bars/) copied
 * from the real wrapper catalog, with one role's BARS entry for one
 * competency stripped out — a manufactured, self-contained
 * bars_available=false pair, decoupled from which pairs (if any) happen to
 * be incomplete in the real tree.
 *
 * @return array{0: string, 1: string, 2: string} the rolesFile,
 *                                                competenciesFile and barsDir override paths, in the order
 *                                                FrameworkCatalogSeeder's constructor takes them
 */
function buildBarsAvailableFalseFixture(string $tmpDir, string $roleCode, string $competencyCode): array
{
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';

    @mkdir("{$tmpDir}/bars", 0755, true);
    copy("{$frameworkBase}/roles.json", "{$tmpDir}/roles.json");
    copy("{$frameworkBase}/competencies.json", "{$tmpDir}/competencies.json");
    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    $roleBars = json_decode(file_get_contents("{$tmpDir}/bars/{$roleCode}.json"), true, 512, JSON_THROW_ON_ERROR);
    unset($roleBars[$competencyCode]);
    file_put_contents("{$tmpDir}/bars/{$roleCode}.json", json_encode($roleBars));

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupBarsAvailableFalseFixture(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

test('ICO/COM competency has bars_available=true', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies');

    $response->assertOk();

    $com = collect($response->json('data'))->firstWhere('code', 'COM');
    expect($com)->not->toBeNull()
        ->and($com['bars_available'])->toBeTrue();
});

test('a competency stripped of BARS anchors reports bars_available=false (fixture)', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_bars_available_false_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildBarsAvailableFalseFixture($tmpDir, 'SRX', 'STG');

    // Seed against the fixture only — the manufactured gap, not the real catalogue.
    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/SRX/competencies');

    $response->assertOk();

    $stg = collect($response->json('data'))->firstWhere('code', 'STG');
    expect($stg)->not->toBeNull()
        ->and($stg['bars_available'])->toBeFalse();

    cleanupBarsAvailableFalseFixture($tmpDir);
});

test('competency list query count does not grow linearly with competency count (N+1 guard)', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    DB::enableQueryLog();

    $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies')
        ->assertOk();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A properly implemented endpoint uses at most ~5 queries (auth, TenantContext, role, competencies, bars).
    // N+1 would produce 15+ queries (one per ICO competency for bars lookup).
    expect($queryCount)->toBeLessThanOrEqual(10);
});
