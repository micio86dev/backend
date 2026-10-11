<?php

declare(strict_types=1);

/**
 * Superadmin organization (client) management — issue #5.
 *
 * The API only listed clients and switched between them; a superadmin had no
 * way to create or rename one. Create/show/update live on `admin/organizations`
 * and are superadmin-only (403 for everyone else, like the rest of that group).
 */

use App\Models\Currency;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a superadmin creates an organization with its three authorization roles', function (): void {
    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->postJson('/api/admin/organizations', [
        'name' => 'Acme Corp',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Acme Corp')
        ->assertJsonPath('data.slug', 'acme-corp');

    $org = Organization::where('slug', 'acme-corp')->firstOrFail();
    expect(Role::where('team_id', $org->id)->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'operator', 'viewer']);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'organization.created',
        'subject_id' => $org->id,
    ]);
});

test('an explicit slug is honoured and a taken slug is refused with a machine code', function (): void {
    Organization::factory()->create(['slug' => 'taken']);
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->postJson('/api/admin/organizations', ['name' => 'X', 'slug' => 'Bad Slug'])
        ->assertUnprocessable()->assertJsonValidationErrors(['slug']);

    $response = $this->withToken($token)->postJson('/api/admin/organizations', ['name' => 'Y', 'slug' => 'taken']);
    $response->assertUnprocessable();
    expect($response->json('errors.slug.0'))->toBe('slug_taken');

    $this->withToken($token)->postJson('/api/admin/organizations', ['name' => 'Z', 'slug' => 'fresh'])
        ->assertCreated()->assertJsonPath('data.slug', 'fresh');
});

test('create requires a name', function (): void {
    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->postJson('/api/admin/organizations', []);

    $response->assertUnprocessable();
    expect($response->json('errors.name.0'))->toBe('name_required');
});

test('a superadmin reads and updates any organization while acting as none or another', function (): void {
    $acme = Organization::factory()->create(['name' => 'Acme']);
    $other = Organization::factory()->create(['name' => 'Other']);
    ['token' => $token] = saSuperadmin();
    $this->withToken($token)->putJson('/api/admin/acting-organization', ['organization_id' => $other->id])->assertOk();

    $this->withToken($token)->getJson("/api/admin/organizations/{$acme->id}")
        ->assertOk()->assertJsonPath('data.name', 'Acme');

    $this->withToken($token)->patchJson("/api/admin/organizations/{$acme->id}", [
        'name' => 'Acme Renamed',
        'primary_color' => '#112233',
        'slug' => 'must-be-ignored',
    ])->assertOk()->assertJsonPath('data.name', 'Acme Renamed')->assertJsonPath('data.primary_color', '#112233');

    $fresh = $acme->fresh();
    expect($fresh->slug)->toBe($acme->slug)
        ->and($other->fresh()->name)->toBe('Other');
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.updated', 'subject_id' => $acme->id]);
});

test('an invalid primary colour is refused on update', function (): void {
    $acme = Organization::factory()->create();
    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->patchJson("/api/admin/organizations/{$acme->id}", ['primary_color' => 'red;}']);

    $response->assertUnprocessable();
    expect($response->json('errors.primary_color.0'))->toBe('primary_color_invalid');
});

test('an unknown organization is a 404', function (): void {
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->getJson('/api/admin/organizations/999999')->assertNotFound();
    $this->withToken($token)->patchJson('/api/admin/organizations/999999', ['name' => 'x'])->assertNotFound();
});

test('an ordinary admin cannot create, read or update organizations through the admin surface', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = saOrgAdmin($org);

    $this->withToken($token)->postJson('/api/admin/organizations', ['name' => 'Nope'])->assertForbidden();
    $this->withToken($token)->getJson("/api/admin/organizations/{$org->id}")->assertForbidden();
    $this->withToken($token)->patchJson("/api/admin/organizations/{$org->id}", ['name' => 'Nope'])->assertForbidden();
    expect(Organization::where('name', 'Nope')->exists())->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'like', 'organization.%')->count())->toBe(0);
});

test('create stores USD when no currency is given, and an active code when it is', function (): void {
    Currency::query()->whereKey('EUR')->update(['rate_per_usd' => '0.892379', 'rate_as_of' => '2026-10-11', 'is_active' => true]);
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->postJson('/api/admin/organizations', ['name' => 'Default Co'])
        ->assertCreated()->assertJsonPath('data.currency_code', 'USD');
    $this->withToken($token)->postJson('/api/admin/organizations', ['name' => 'Euro Co', 'currency_code' => 'EUR'])
        ->assertCreated()->assertJsonPath('data.currency_code', 'EUR');

    expect(Organization::where('slug', 'euro-co')->firstOrFail()->currency_code)->toBe('EUR');
});

test('create and edit refuse an inactive or unknown currency with a machine code', function (string $code): void {
    $acme = Organization::factory()->create();
    ['token' => $token] = saSuperadmin();

    $create = $this->withToken($token)->postJson('/api/admin/organizations', ['name' => 'Nope Co', 'currency_code' => $code]);
    $create->assertUnprocessable();
    expect($create->json('errors.currency_code.0'))->toBe('currency_unavailable')
        ->and(Organization::where('slug', 'nope-co')->exists())->toBeFalse();

    $edit = $this->withToken($token)->patchJson("/api/admin/organizations/{$acme->id}", ['currency_code' => $code]);
    $edit->assertUnprocessable();
    expect($edit->json('errors.currency_code.0'))->toBe('currency_unavailable')
        ->and($acme->fresh()->currency_code)->toBe('USD');
})->with(['inactive EUR' => 'EUR', 'unknown' => 'XYZ']);

test('a superadmin edit of the currency is covered by organization.updated with before and after', function (): void {
    Currency::query()->whereKey('EUR')->update(['rate_per_usd' => '0.892379', 'rate_as_of' => '2026-10-11', 'is_active' => true]);
    $acme = Organization::factory()->create();
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->patchJson("/api/admin/organizations/{$acme->id}", ['currency_code' => 'EUR'])
        ->assertOk()->assertJsonPath('data.currency_code', 'EUR');

    $row = DB::table('audit_logs')->where('action', 'organization.updated')->where('subject_id', $acme->id)->first();
    expect(json_decode($row->before, true))->toBe(['currency_code' => 'USD'])
        ->and(json_decode($row->after, true))->toBe(['currency_code' => 'EUR']);
});
