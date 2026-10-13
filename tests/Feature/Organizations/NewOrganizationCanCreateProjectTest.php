<?php

declare(strict_types=1);

/**
 * Owner report: after creating a NEW client in the backoffice and then a new
 * project, there was no framework version to select, so the project could not
 * be created. This drives the real routes end to end.
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a client created by a superadmin can list its version and create its first project', function (): void {
    (new FrameworkCatalogSeeder)->run();

    // Another tenant with its own version: must never leak into the new one.
    $other = Organization::factory()->create();
    $otherVersion = TenantContextScope::runFor(
        $other->id,
        fn () => FrameworkVersion::factory()->create(['organization_id' => $other->id]),
    );

    ['token' => $superToken] = saSuperadmin();
    $created = $this->withToken($superToken)->postJson('/api/admin/organizations', ['name' => 'Brand New Client'])
        ->assertCreated();
    $org = Organization::findOrFail($created->json('data.id'));

    $versions = FrameworkVersion::withoutGlobalScopes()->where('organization_id', $org->id)->get();
    expect($versions)->toHaveCount(1);
    expect($versions->first()->is_locked)->toBeFalse();

    ['token' => $adminToken] = saOrgAdmin($org);

    $list = $this->withToken($adminToken)->getJson('/api/framework/versions')->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())
        ->toBe([$versions->first()->id])
        ->not->toContain($otherVersion->id);

    $templateId = TenantContextScope::runFor($org->id, fn (): int => templateIdForCurrentOrg());

    $this->withToken($adminToken)->postJson('/api/projects', [
        'framework_version_id' => $versions->first()->id,
        'slug' => 'first-project',
        'name' => 'First project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'avatar_template_id' => $templateId,
    ])->assertCreated();

    expect($versions->first()->fresh()->is_locked)->toBeTrue();
});
