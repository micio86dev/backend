<?php

declare(strict_types=1);

/**
 * Privilege-escalation guards on /api/users writes (backoffice-missing-pages
 * D4). This surface can grant `admin` — every scenario here is a binding
 * invariant per the spec, not a preference.
 *
 * REQ: Privilege-Escalation Guards On Write,
 *      BEAI Organizational Roles Excluded From This Surface
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

test('a crafted organization_id in the request body is ignored', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($orgA, 'admin');

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'Forger',
        'email' => 'forger@example.test',
        'password' => 'password12345',
        'role' => 'viewer',
        'organization_id' => $orgB->id,
    ]);

    $response->assertCreated();
    $created = User::where('email', 'forger@example.test')->firstOrFail();
    expect($created->organization_id)->toBe($orgA->id);
});

test('a crafted is_superadmin in the request body is ignored', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'Escalator',
        'email' => 'escalator@example.test',
        'password' => 'password12345',
        'role' => 'viewer',
        'is_superadmin' => true,
    ]);

    $response->assertCreated();
    $created = User::where('email', 'escalator@example.test')->firstOrFail();
    expect($created->is_superadmin)->toBeFalse();
});

test('a free-form or foreign role value is rejected 422 and creates no role row', function (string $badRole): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'Bad Role',
        'email' => 'bad-role-'.uniqid().'@example.test',
        'password' => 'password12345',
        'role' => $badRole,
    ]);

    $response->assertUnprocessable();
    expect(User::where('email', 'like', 'bad-role-%@example.test')->exists())->toBeFalse();
})->with(['superadmin', 'ICO']);

test('PATCH with a free-form role is rejected 422 and the role is unchanged', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $target->assignRole(SpatieRole::firstOrCreate(['name' => 'viewer', 'guard_name' => 'api', 'team_id' => $org->id]));

    $response = $this->withToken($token)->patchJson("/api/users/{$target->id}", ['role' => 'superadmin']);

    $response->assertUnprocessable();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($target->fresh()->hasRole('viewer'))->toBeTrue();
});

test('role_code in a user payload is not honoured, not persisted, and never returned', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'Conflation Check',
        'email' => 'conflation@example.test',
        'password' => 'password12345',
        'role' => 'viewer',
        'role_code' => 'ICO',
    ]);

    $response->assertCreated();
    expect($response->json('data.role'))->toBe('viewer');
    expect(array_key_exists('role_code', $response->json('data')))->toBeFalse();
    expect($response->getContent())->not->toContain('role_code');
});

test('a BEAI organizational role code is not a valid authorization role on PATCH', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $target->assignRole(SpatieRole::firstOrCreate(['name' => 'viewer', 'guard_name' => 'api', 'team_id' => $org->id]));

    $response = $this->withToken($token)->patchJson("/api/users/{$target->id}", ['role' => 'ICO']);

    $response->assertUnprocessable();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($target->fresh()->hasRole('viewer'))->toBeTrue();
});

test('a role change takes effect on the very next request — no stale team-scoped cache', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $target->assignRole(SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]));

    $this->withToken($token)->patchJson("/api/users/{$target->id}", ['role' => 'admin'])->assertOk();

    // Fresh registrar state, as a brand-new request would have — the NULL-team
    // trap (ProvisionOrganizationCommand.php:147-161): a role row written
    // without an explicit team_id is invisible to hasRole() even though it
    // exists in the table.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    expect($target->fresh()->hasRole('admin'))->toBeTrue();

    $adminRole = SpatieRole::where('name', 'admin')->where('guard_name', 'api')->where('team_id', $org->id)->firstOrFail();
    expect(
        DB::table('model_has_roles')
            ->where('role_id', $adminRole->id)
            ->where('model_id', $target->id)
            ->whereNull('team_id')
            ->exists()
    )->toBeFalse();
});
