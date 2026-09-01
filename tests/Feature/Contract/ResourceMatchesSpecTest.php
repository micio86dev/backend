<?php

declare(strict_types=1);

/**
 * The published spec must describe what the API actually returns.
 *
 * Several resources carry a hand-written `@scramble-return` docblock, because
 * Scramble cannot infer every shape statically. That override is load-bearing
 * and silent: it does not have to agree with the array the method returns, and
 * when it stops agreeing, nothing fails. The export succeeds, CI's fresh-export
 * diff passes — it compares the export to itself — and the two Nuxt apps
 * generate a typed client missing fields the API has been sending all along.
 * `logo_url` and `primary_color` shipped exactly that way: present in every
 * response, absent from the spec, invisible until a `bun run codegen` deleted
 * them from the client and broke three components at once.
 *
 * So the response is compared to the COMMITTED spec, on a real request. It is
 * the only check in the chain that reads both sides.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array<int, string>
 */
function specProperties(string $schema): array
{
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true);

    expect($spec)->toBeArray()
        ->and($spec['components']['schemas'][$schema] ?? null)->not->toBeNull(
            "openapi.json declares no schema named {$schema}"
        );

    $properties = array_keys($spec['components']['schemas'][$schema]['properties']);
    sort($properties);

    return $properties;
}

function contractAdminToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id,
    ]));
    app(TenantResolver::class)->setOrgId($org->id);

    return auth('api')->login($user);
}

test('GET /api/organization returns exactly what OrganizationResource declares', function (): void {
    $org = Organization::factory()->create();
    $token = contractAdminToken($org);

    $response = $this->withToken($token)->getJson('/api/organization');
    $response->assertOk();

    $actual = array_keys($response->json('data'));
    sort($actual);

    expect($actual)->toBe(specProperties('OrganizationResource'));
});

test('GET /api/projects/{id} returns exactly what ProjectResource declares', function (): void {
    $org = Organization::factory()->create();
    $token = contractAdminToken($org);
    $project = Project::factory()->create();

    $response = $this->withToken($token)->getJson('/api/projects/'.$project->id);
    $response->assertOk();

    $actual = array_keys($response->json('data'));
    sort($actual);

    expect($actual)->toBe(specProperties('ProjectResource'));
});

test('GET /api/auth/me returns exactly the envelope the spec declares', function (): void {
    // Not a Resource — an inline array in the controller, with its own
    // hand-written `@scramble-return` for the same reason and the same risk.
    // Both Nuxt apps render their navigation from the `abilities` map, so a
    // group that exists in code and not in the spec is a navigation decision
    // no client can make.
    $org = Organization::factory()->create();
    $token = contractAdminToken($org);

    $response = $this->withToken($token)->getJson('/api/auth/me');
    $response->assertOk();

    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true);
    $declared = $spec['paths']['/auth/me']['get']['responses']['200']
        ['content']['application/json']['schema']['properties'];

    $actualTop = array_keys($response->json());
    sort($actualTop);
    $declaredTop = array_keys($declared);
    sort($declaredTop);

    expect($actualTop)->toBe($declaredTop);

    // One level deeper for the abilities map: a missing GROUP is the failure
    // that matters, and comparing only the top level would pass with
    // `abilities` present and empty.
    $actualGroups = array_keys($response->json('abilities'));
    sort($actualGroups);
    $declaredGroups = array_keys($declared['abilities']['properties']);
    sort($declaredGroups);

    expect($actualGroups)->toBe($declaredGroups);
});
