<?php

declare(strict_types=1);

/**
 * The /settings surface is admin-only, AT THE SERVER.
 *
 * The backoffice hides the page from an operator, but hiding is a courtesy,
 * not a control: a client is free to ignore anything it is told, and the whole
 * page is reachable by typing the URL or calling the endpoints directly. So
 * every endpoint that page uses is asserted here against BOTH non-admin roles,
 * one test per endpoint rather than one test that stops at the first failure —
 * a single hole is the only thing that matters and a fused assertion would hide
 * the rest of them behind it.
 *
 * `viewer` is the backoffice's "observer". The name differs because Spatie's
 * role is `viewer` and the product calls it an observer; they are one role.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function settingsToken(Organization $org, string $role): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => $role, 'guard_name' => 'api', 'team_id' => $org->id,
    ]));
    app(TenantResolver::class)->setOrgId($org->id);

    return auth('api')->login($user);
}

/**
 * Every write the settings page can perform, as [method, path, body].
 *
 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function settingsWrites(): array
{
    return [
        ['patchJson', '/api/organization', ['name' => 'Renamed by someone who may not']],
        ['deleteJson', '/api/organization/logo', []],
        ['postJson', '/api/users', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'admin']],
        ['postJson', '/api/llm-credentials', ['name' => 'X', 'provider' => 'gemini', 'api_key' => 'k']],
        ['postJson', '/api/avatar-templates', ['name' => 'X', 'provider' => 'heygen', 'config' => []]],
    ];
}

test('an operator is refused every settings WRITE', function (string $method, string $path, array $body): void {
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'operator');

    $this->withToken($token)->{$method}($path, $body)->assertForbidden();
})->with(settingsWrites());

test('an observer is refused every settings WRITE', function (string $method, string $path, array $body): void {
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'viewer');

    $this->withToken($token)->{$method}($path, $body)->assertForbidden();
})->with(settingsWrites());

test('an operator is refused the settings READS that are admin-only', function (string $path): void {
    // `GET /api/organization` is deliberately absent: reading the
    // organization's own name and branding is not privileged, and both apps
    // need it to render. What an operator may not do is CHANGE it, which the
    // write cases above cover.
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'operator');

    $this->withToken($token)->getJson($path)->assertForbidden();
})->with([
    '/api/users',
    '/api/llm-credentials',
    '/api/avatar-templates',
    '/api/avatar-templates/export',
]);

test('an observer is refused those same reads', function (string $path): void {
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'viewer');

    $this->withToken($token)->getJson($path)->assertForbidden();
})->with([
    '/api/users',
    '/api/llm-credentials',
    '/api/avatar-templates',
    '/api/avatar-templates/export',
]);

// ─── The abilities map the backoffice renders from ───────────────────────────

test('an admin is told they may reach settings', function (): void {
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'admin');

    $response = $this->withToken($token)->getJson('/api/auth/me');

    $response->assertOk();
    $response->assertJsonPath('abilities.organization.update', true);
    $response->assertJsonPath('abilities.users.viewAny', true);
    $response->assertJsonPath('abilities.avatarTemplates.viewAny', true);
    $response->assertJsonPath('abilities.llmCredentials.viewAny', true);
});

test('an operator is told they may NOT, matching what the endpoints do', function (): void {
    // The point of the map is that it cannot disagree with the refusals
    // asserted above — both come from the same policies. This asserts the
    // agreement rather than trusting it.
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'operator');

    $response = $this->withToken($token)->getJson('/api/auth/me');

    $response->assertOk();
    $response->assertJsonPath('abilities.organization.update', false);
    $response->assertJsonPath('abilities.users.viewAny', false);
    $response->assertJsonPath('abilities.avatarTemplates.viewAny', false);
    $response->assertJsonPath('abilities.llmCredentials.viewAny', false);

    // Still an operator, not a viewer: projects remain theirs to run.
    $response->assertJsonPath('abilities.projects.create', true);
});

test('an observer may create nothing at all', function (): void {
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'viewer');

    $response = $this->withToken($token)->getJson('/api/auth/me');

    $response->assertJsonPath('abilities.projects.viewAny', true);
    $response->assertJsonPath('abilities.projects.create', false);
    $response->assertJsonPath('abilities.organization.update', false);
});

// ─── Per-record abilities on the project list ────────────────────────────────

test('a project row tells an operator it may be edited but not deleted', function (): void {
    // The two answers differ for the SAME row and the SAME user, which is
    // exactly why a role name in the client cannot produce them: `update` is
    // an operator's to make, `delete` is not.
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'operator');
    $project = Project::factory()->create();

    $response = $this->withToken($token)->getJson('/api/projects/'.$project->id);

    $response->assertOk();
    $response->assertJsonPath('data.can.update', true);
    $response->assertJsonPath('data.can.delete', false);
});

test('the same row tells an admin it may be deleted', function (): void {
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'admin');
    $project = Project::factory()->create();

    $response = $this->withToken($token)->getJson('/api/projects/'.$project->id);

    $response->assertJsonPath('data.can.update', true);
    $response->assertJsonPath('data.can.delete', true);
});

test('an observer is offered neither', function (): void {
    $org = Organization::factory()->create();
    $token = settingsToken($org, 'viewer');
    $project = Project::factory()->create();

    $response = $this->withToken($token)->getJson('/api/projects/'.$project->id);

    $response->assertJsonPath('data.can.update', false);
    $response->assertJsonPath('data.can.delete', false);
});
