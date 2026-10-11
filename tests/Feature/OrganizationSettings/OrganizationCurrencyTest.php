<?php

declare(strict_types=1);

/**
 * The display currency of an organization (organization-currencies OC-03).
 *
 * `currency_code` only changes how money is DISPLAYED. The rules under test: an
 * admin may pick ACTIVE catalogue codes only, operators and viewers may not write,
 * the change is audited with before/after, and no tenant can reach another's code.
 */

use App\Models\Currency;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The migration seeds USD active and EUR inactive without a rate.
    Currency::query()->whereKey('EUR')->update(['rate_per_usd' => '0.892379', 'rate_as_of' => '2026-10-11', 'is_active' => true]);
});

test('every role reads USD for an organization that never chose', function (string $role): void {
    $org = Organization::factory()->create();

    $this->withToken(authTokenForRole($org, $role))->getJson('/api/organization')
        ->assertOk()->assertJsonPath('data.currency_code', 'USD');
})->with(['admin', 'operator', 'viewer']);

test('an admin switches to an active currency and the change is audited before and after', function (): void {
    $org = Organization::factory()->create();

    $this->withToken(authTokenForRole($org, 'admin'))
        ->patchJson('/api/organization', ['currency_code' => 'EUR'])
        ->assertOk()->assertJsonPath('data.currency_code', 'EUR');

    expect($org->fresh()->currency_code)->toBe('EUR');
    $row = DB::table('audit_logs')->where('action', 'organization.currency_changed')->first();
    expect($row)->not->toBeNull()
        ->and($row->subject_id)->toBe($org->id)
        ->and($row->organization_id)->toBe($org->id)
        ->and(json_decode($row->before, true))->toBe(['currency_code' => 'USD'])
        ->and(json_decode($row->after, true))->toBe(['currency_code' => 'EUR']);
});

test('an unchanged currency writes no audit entry', function (): void {
    $org = Organization::factory()->create();

    $this->withToken(authTokenForRole($org, 'admin'))
        ->patchJson('/api/organization', ['currency_code' => 'USD', 'name' => 'Renamed'])->assertOk();

    expect(DB::table('audit_logs')->where('action', 'organization.currency_changed')->count())->toBe(0);
});

test('an inactive, unknown or malformed code is refused with a machine code and nothing changes', function (mixed $code): void {
    Currency::factory()->rated()->create(['code' => 'GBP']); // exists but inactive
    $org = Organization::factory()->create();

    $response = $this->withToken(authTokenForRole($org, 'admin'))
        ->patchJson('/api/organization', ['currency_code' => $code]);

    $response->assertUnprocessable();
    expect($response->json('errors.currency_code.0'))->toBe('currency_unavailable')
        ->and($org->fresh()->currency_code)->toBe('USD')
        ->and(DB::table('audit_logs')->where('action', 'organization.currency_changed')->count())->toBe(0);
})->with(['inactive' => 'GBP', 'unknown' => 'XYZ', 'lowercase' => 'eur', 'long' => 'EURO', 'null' => null, 'array' => [['EUR']]]);

test('an operator and a viewer cannot change the currency', function (string $role): void {
    $org = Organization::factory()->create();

    $this->withToken(authTokenForRole($org, $role))
        ->patchJson('/api/organization', ['currency_code' => 'EUR'])->assertForbidden();

    expect($org->fresh()->currency_code)->toBe('USD');
})->with(['operator', 'viewer']);

test('an organization that already uses a currency keeps reading it after the catalogue deactivates nothing else', function (): void {
    // Display stays available for an in-use code: the catalogue guard refuses to
    // deactivate it (409), so there is no extra logic here to prove beyond the read.
    $org = Organization::factory()->create(['currency_code' => 'EUR']);

    $this->withToken(authTokenForRole($org, 'viewer'))->getJson('/api/organization')
        ->assertOk()->assertJsonPath('data.currency_code', 'EUR');
});

test('an admin of org B never sees org A currency and cannot change it', function (): void {
    $orgA = Organization::factory()->create(['currency_code' => 'EUR']);
    $orgB = Organization::factory()->create();
    $tokenB = authTokenForRole($orgB, 'admin');

    $read = $this->withToken($tokenB)->getJson('/api/organization');
    $read->assertOk()->assertJsonPath('data.currency_code', 'USD');

    $this->withToken($tokenB)->patchJson('/api/organization', ['currency_code' => 'USD', 'id' => $orgA->id, 'organization_id' => $orgA->id])
        ->assertOk();
    $this->withToken($tokenB)->patchJson('/api/organization', ['currency_code' => 'EUR'])->assertOk();

    expect($orgA->fresh()->currency_code)->toBe('EUR')
        ->and($orgB->fresh()->currency_code)->toBe('EUR');
    expect(DB::table('audit_logs')->where('action', 'organization.currency_changed')->pluck('organization_id')->all())
        ->toBe([$orgB->id]);
});
