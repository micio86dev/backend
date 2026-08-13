<?php

declare(strict_types=1);

/**
 * GET/POST/PATCH /api/users — org-scoped, admin-only CRUD
 * (backoffice-missing-pages D4).
 *
 * REQ: Org-Scoped User CRUD Endpoints, Admin-Only Authorization On Every Verb
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('GET /api/users returns only same-org users (3 org A, 2 org B)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($orgA, 'admin');

    User::factory()->count(2)->create(['organization_id' => $orgA->id]);
    User::factory()->count(2)->create(['organization_id' => $orgB->id]);

    $response = $this->withToken($token)->getJson('/api/users');

    $response->assertOk();
    // 2 extra + the admin caller themselves = 3.
    expect($response->json('data'))->toHaveCount(3);
    $ids = collect($response->json('data'))->pluck('id');
    foreach (User::where('organization_id', $orgB->id)->pluck('id') as $orgBId) {
        expect($ids)->not->toContain($orgBId);
    }
});

test('admin can create a user', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'New Operator',
        'email' => 'new-operator@example.test',
        'password' => 'a-strong-password-123',
        'role' => 'operator',
    ]);

    $response->assertCreated();
    expect($response->json('data.role'))->toBe('operator');
    expect($response->json('data.email'))->toBe('new-operator@example.test');

    $created = User::where('email', 'new-operator@example.test')->firstOrFail();
    expect($created->organization_id)->toBe($org->id);
});

test('admin can update a user name and role', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $target->assignRole(Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'api', 'team_id' => $org->id]));

    $response = $this->withToken($token)->patchJson("/api/users/{$target->id}", [
        'name' => 'Renamed',
        'role' => 'operator',
    ]);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Renamed');
    expect($response->json('data.role'))->toBe('operator');
});

test('operator and viewer are denied every write verb', function (string $role): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, $role);
    $target = User::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)->postJson('/api/users', [
        'name' => 'X', 'email' => 'x@example.test', 'password' => 'password123', 'role' => 'viewer',
    ])->assertForbidden();

    $this->withToken($token)->patchJson("/api/users/{$target->id}", ['name' => 'Y'])->assertForbidden();

    $this->withToken($token)->postJson("/api/users/{$target->id}/deactivate")->assertForbidden();

    $this->withToken($token)->postJson("/api/users/{$target->id}/activate")->assertForbidden();
})->with(['operator', 'viewer']);
