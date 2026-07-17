<?php

/**
 * RED — 11.8: bars_available flag in competency list (C3).
 *
 * ICO/COM → bars_available=true; FLL/PRS → bars_available=false.
 * Also guards against N+1 query regression.
 *
 * Refs spec: "bars_available flag is true for BARS-covered competencies".
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder())->run();
});

test('ICO/COM competency has bars_available=true', function (): void {
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

test('FLL/PRS competency has bars_available=false (gap competency)', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/FLL/competencies');

    $response->assertOk();

    $prs = collect($response->json('data'))->firstWhere('code', 'PRS');
    expect($prs)->not->toBeNull()
        ->and($prs['bars_available'])->toBeFalse();
});

test('competency list query count does not grow linearly with competency count (N+1 guard)', function (): void {
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
