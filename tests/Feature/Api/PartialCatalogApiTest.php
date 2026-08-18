<?php

/**
 * RED — 11.6: API responds correctly with partial catalog (C3).
 *
 * A role with no BARS file at all (fixture) → GET /api/framework/roles
 * still returns 200 with that role listed.
 * Refs spec: "API responds correctly with a partial catalog (remaining
 * gaps)".
 *
 * History: this used the real SRX role, which had no bars/SRX.json until
 * bars-catalogue-completion Phase 6 authored it. Post-completion, all 5
 * declared roles have a BARS file, so "role with no BARS file" has no
 * real-catalogue occurrence — task 7.4 moves this test onto a fixture
 * that manufactures its own missing-BARS-file role, the same pattern
 * tests/Feature/Seeders/GracefulMissingFileTest.php already uses for the
 * seeder-level scenario.
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * Build a bars/ directory copied from the real wrapper catalog, minus one
 * role's file — a manufactured, self-contained role_no_bars fixture,
 * decoupled from which role (if any) happens to lack a BARS file in the
 * real tree.
 */
function buildMissingBarsRoleFixture(string $tmpDir, string $omitRole): string
{
    $sourceBars = dirname(base_path()).'/docs/app_description/02-domain/framework/bars';
    mkdir($tmpDir, 0755, true);

    foreach (glob("{$sourceBars}/*.json") as $barsFile) {
        $role = pathinfo($barsFile, PATHINFO_FILENAME);
        if ($role === $omitRole) {
            continue;
        }
        copy($barsFile, "{$tmpDir}/{$role}.json");
    }

    return $tmpDir;
}

function cleanupMissingBarsRoleFixture(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    rmdir($tmpDir);
}

beforeEach(function (): void {
    $this->fixtureBarsDir = sys_get_temp_dir().'/c3_partial_catalog_api_'.uniqid();
    buildMissingBarsRoleFixture($this->fixtureBarsDir, 'SRX');
    (new FrameworkCatalogSeeder(barsDir: $this->fixtureBarsDir))->run(); // SRX has no bars — manufactured partial catalog state
});

afterEach(function (): void {
    cleanupMissingBarsRoleFixture($this->fixtureBarsDir);
});

test('GET /api/framework/roles returns 200 with SRX included even when SRX has no BARS', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    $response->assertOk()
        ->assertJsonCount(5, 'data');

    $codes = collect($response->json('data'))->pluck('code')->toArray();
    expect($codes)->toContain('SRX');
});

test('GET /api/framework/roles does not return 500 with partial catalog', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    $response->assertStatus(200);
});
