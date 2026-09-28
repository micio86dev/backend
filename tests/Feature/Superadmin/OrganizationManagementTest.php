<?php

declare(strict_types=1);

/**
 * Superadmin organization (client) management — issue #5.
 *
 * The API only listed clients and switched between them; a superadmin had no
 * way to create or rename one. Create/show/update live on `admin/organizations`
 * and are superadmin-only (403 for everyone else, like the rest of that group).
 */

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
