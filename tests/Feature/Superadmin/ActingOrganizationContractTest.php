<?php

declare(strict_types=1);

/**
 * The 409 `organization_context_required` contract for a superadmin with NO acting organization.
 *
 * The identity is the production one — `organization_id = null`, `is_superadmin = true` — never a superadmin that
 * also carries an organization (that is not who uses the product, and it is the fixture that hid this whole class
 * of defect). `saSuperadmin()` builds it; `ActingOrganization::set()` narrows it to one client.
 *
 * Every refusal is asserted twice: the status AND the body (`message`, the code the backoffice translates), and
 * that nothing was written. A 409 that still created the row would be worse than the 422 it replaces.
 *
 * The status matrix (`AuthMatrixUserActorsTest`) pins the status per route; this file pins what the matrix cannot:
 * the machine code, the absence of a write, `GET /organization` staying `data: null`, and tenant isolation.
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\ActingOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * The org-scoped operations a superadmin with no acting organization must be refused on, each with a payload that
 * would FAIL validation if validation ran first (so a 409 also proves the middleware runs before it).
 */
dataset('org required operations', [
    'POST /projects' => ['POST', '/api/projects', []],
    'PATCH /organization' => ['PATCH', '/api/organization', ['name' => '']],
    'POST /organization/logo' => ['POST', '/api/organization/logo', []],
    'DELETE /organization/logo' => ['DELETE', '/api/organization/logo', []],
    'POST /m2m/clients' => ['POST', '/api/m2m/clients', []],
    'GET /m2m/clients' => ['GET', '/api/m2m/clients', []],
]);

test('a superadmin with no acting organization is refused with the machine code on every org-required operation', function (string $method, string $uri, array $payload): void {
    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->json($method, $uri, $payload);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->json('message'))->toBe('organization_context_required')
        // The retired bespoke code must not survive in any body.
        ->and($response->json('error'))->toBeNull();
})->with('org required operations');

test('the refusals write nothing', function (): void {
    Storage::fake();
    $org = Organization::factory()->create(['name' => 'Acme', 'logo_path' => 'organization-logos/keep.png']);
    $member = User::factory()->create(['organization_id' => $org->id]);
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->postJson('/api/projects', [])->assertStatus(409);
    $this->withToken($token)->patchJson('/api/organization', ['name' => 'Renamed'])->assertStatus(409);
    $this->withToken($token)->post('/api/organization/logo', ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])->assertStatus(409);
    $this->withToken($token)->deleteJson('/api/organization/logo')->assertStatus(409);
    $this->withToken($token)->postJson('/api/m2m/clients', ['name' => 'Orphan', 'abilities' => ['participants:create']])->assertStatus(409);
    $this->withToken($token)->postJson("/api/users/{$member->id}/deactivate")->assertStatus(409);
    $this->withToken($token)->postJson("/api/users/{$member->id}/activate")->assertStatus(409);

    expect(Project::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(ApiClient::query()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([])
        ->and($org->fresh()->name)->toBe('Acme')
        ->and($org->fresh()->logo_path)->toBe('organization-logos/keep.png')
        ->and($member->fresh()->deactivated_at)->toBeNull();
});

test('a valid project payload is still refused with 409, not created and not 422', function (): void {
    $org = Organization::factory()->create();
    $existing = saProject($org, 'existing');
    ['token' => $token] = saSuperadmin();

    $response = $this->withToken($token)->postJson('/api/projects', [
        'framework_version_id' => $existing->framework_version_id,
        'slug' => 'brand-new',
        'name' => 'Brand New',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'competency_ids' => [],
    ]);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->json('message'))->toBe('organization_context_required')
        ->and(Project::query()->withoutGlobalScopes()->where('slug', 'brand-new')->exists())->toBeFalse();
});

test('GET /organization with no acting organization still answers data null, not 409', function (): void {
    // Decided, not accidental: the backoffice shell reads this on every page to paint the brand colour, and the
    // settings page degrades on `null`. Only the WRITES refuse.
    ['token' => $token] = saSuperadmin();

    $this->withToken($token)->getJson('/api/organization')
        ->assertOk()
        ->assertExactJson(['data' => null]);
});

test('a superadmin acting as an organization is served on every operation, against THAT organization', function (): void {
    Storage::fake();
    $orgA = Organization::factory()->create(['name' => 'Acme']);
    $orgB = Organization::factory()->create(['name' => 'Globex']);
    ApiClient::factory()->count(2)->create(['organization_id' => $orgA->id]);
    ApiClient::factory()->count(3)->create(['organization_id' => $orgB->id]);

    ['user' => $user, 'token' => $token] = saSuperadmin();
    app(ActingOrganization::class)->set((int) $user->id, (int) $orgA->id);

    $this->withToken($token)->patchJson('/api/organization', ['name' => 'Acme Renamed'])->assertOk();
    expect($orgA->fresh()->name)->toBe('Acme Renamed')
        ->and($orgB->fresh()->name)->toBe('Globex');

    $this->withToken($token)->post('/api/organization/logo', ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])->assertOk();
    expect($orgA->fresh()->logo_path)->not->toBeNull()
        ->and($orgB->fresh()->logo_path)->toBeNull();

    $this->withToken($token)->deleteJson('/api/organization/logo')->assertSuccessful();
    expect($orgA->fresh()->logo_path)->toBeNull();

    $list = $this->withToken($token)->getJson('/api/m2m/clients')->assertOk();
    expect($list->json('data'))->toHaveCount(2);
});

test('an organization admin is never served another organization, and is unaffected by the middleware', function (): void {
    $orgA = Organization::factory()->create(['name' => 'Acme']);
    $orgB = Organization::factory()->create(['name' => 'Globex']);
    ApiClient::factory()->count(2)->create(['organization_id' => $orgA->id]);
    ApiClient::factory()->count(3)->create(['organization_id' => $orgB->id]);

    ['token' => $adminA] = saOrgAdmin($orgA);

    // Another organization's id in the body or query is not a lever: the organization resolves from the context.
    $this->withToken($adminA)->patchJson('/api/organization?organization_id='.$orgB->id, [
        'name' => 'Acme Renamed',
        'organization_id' => $orgB->id,
    ])->assertOk();

    expect($orgA->fresh()->name)->toBe('Acme Renamed')
        ->and($orgB->fresh()->name)->toBe('Globex');

    $list = $this->withToken($adminA)->getJson('/api/m2m/clients')->assertOk();
    expect($list->json('data'))->toHaveCount(2);

    $created = $this->withToken($adminA)->postJson('/api/m2m/clients', [
        'name' => 'Mine',
        'abilities' => ['participants:create'],
    ])->assertCreated();
    expect(ApiClient::query()->findOrFail($created->json('data.id'))->organization_id)->toBe($orgA->id);
});
