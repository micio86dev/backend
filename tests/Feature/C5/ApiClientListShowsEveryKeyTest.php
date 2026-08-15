<?php

declare(strict_types=1);

/**
 * ApiClientListShowsEveryKeyTest (generated-client-truth-and-session-safety D5).
 *
 * REQ: API-Keys Table Shows Every Key, Not Only The First Page
 * (openspec/changes/generated-client-truth-and-session-safety/specs/admin-backoffice/spec.md)
 *
 * `ApiClientController::index()` paginated at 20 while `ApiKeysPanel.vue`
 * read only `response.data` — a 21st key was silently unreachable. The
 * panel answers a whole-set question ("what can authenticate against my
 * org"), so the fix is an unpaginated org-scoped list, not a client-side
 * pagination UI (design.md D5).
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function apiClientListAdmin(Organization $org): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

test('GET /api/m2m/clients returns all 25 org-scoped clients with no meta envelope', function (): void {
    $org = Organization::factory()->create();
    $token = apiClientListAdmin($org);

    ApiClient::factory()->count(25)->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->getJson('/api/m2m/clients');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(25);
    expect($response->json())->not->toHaveKey('meta');
});

test("the demo seeder's three fixture clients all appear in the unpaginated list", function (): void {
    (new FrameworkCatalogSeeder)->run();
    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $token = apiClientListAdmin($org);

    $response = $this->withToken($token)->getJson('/api/m2m/clients');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names->filter(fn (string $name): bool => str_starts_with($name, 'beai-demo-')))->toHaveCount(3);
});
