<?php

declare(strict_types=1);

use App\Models\Currency;
use App\Models\Organization;

test('every role reads the active catalogue only, with the rate as a decimal string', function (string $role): void {
    Currency::factory()->active()->create(['code' => 'GBP', 'symbol' => '£', 'rate_per_usd' => '0.790000']);
    Currency::factory()->rated()->create(['code' => 'JPY']);
    $org = Organization::factory()->create();

    $response = $this->withToken(authTokenForRole($org, $role))->getJson('/api/currencies');

    $response->assertOk();
    $codes = collect($response->json('data'))->pluck('code')->all();
    expect($codes)->toContain('USD', 'GBP')->not->toContain('EUR', 'JPY');

    $gbp = collect($response->json('data'))->firstWhere('code', 'GBP');
    expect($gbp)->toBe([
        'code' => 'GBP',
        'symbol' => '£',
        'decimals' => 2,
        'rate_per_usd' => '0.790000',
        'rate_as_of' => '2026-10-12',
        'is_active' => true,
    ]);
})->with(['admin', 'operator', 'viewer']);

test('a superadmin reads the active catalogue too', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->getJson('/api/currencies')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'USD');
});

test('an unauthenticated read is refused', function (): void {
    $this->getJson('/api/currencies')->assertUnauthorized();
});
