<?php

declare(strict_types=1);

/**
 * The Dashboard is the operator's landing page. Before this change,
 * `ai_requests` was empty in production: `DashboardController::metrics()`
 * summed zero tokens and `percentile()` returned `null` for both p50 and p95
 * (design D14; proposal's stated production consequence).
 *
 * `beai:demo-seed` now writes 26 `ai_requests` rows, hand-authored so the
 * SAME nearest-rank formula the controller uses yields p50=1284ms /
 * p95=7436ms exactly (`LatencyLadderTest` pins the fixture side of this;
 * this test proves it end-to-end through the real HTTP endpoint).
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function demoDashboardAdminUser(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('GET /api/dashboard/metrics returns non-zero token totals and integer p50/p95 with p95 > p50 after beai:demo-seed', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $token = demoDashboardAdminUser($this->org);

    $response = $this->withToken($token)->getJson('/api/dashboard/metrics');

    $response->assertOk();
    $body = $response->json('data');

    expect($body['ai_usage']['input_tokens'])->toBeGreaterThan(0);
    expect($body['ai_usage']['output_tokens'])->toBeGreaterThan(0);
    expect($body['ai_usage']['latency_ms_p50'])->toBeInt();
    expect($body['ai_usage']['latency_ms_p95'])->toBeInt();
    expect($body['ai_usage']['latency_ms_p50'])->toBe(1284);
    expect($body['ai_usage']['latency_ms_p95'])->toBe(7436);
    expect($body['ai_usage']['latency_ms_p95'])->toBeGreaterThan($body['ai_usage']['latency_ms_p50']);
});
