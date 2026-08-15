<?php

declare(strict_types=1);

/**
 * AdminPasswordResetRevocationTest (generated-client-truth-and-session-safety D4).
 *
 * REQ: Admin-Initiated Password Write Invalidates The Target's Sessions
 * (openspec/changes/generated-client-truth-and-session-safety/specs/user-management/spec.md)
 *
 * `UserController::update()` did not set `password_changed_at` when an admin
 * changed a target's password, so `RejectStaleCredentials` — the same
 * mechanism `user-profile-self-service` relies on for the self-service path
 * — never fired here. This is the one case revocation matters most: an
 * admin resetting a compromised account, and it was the one case that
 * failed silently.
 */

use App\Models\Organization;
use App\Models\User;

test('admin password reset on update() retires the target token on /auth/me and /auth/refresh', function (): void {
    $org = Organization::factory()->create();
    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);

    $tokenTarget = (string) auth('api')->login($target);

    $this->travel(2)->seconds();

    resetAuthGuardState();
    $response = $this->withToken($adminToken)->patchJson("/api/users/{$target->id}", [
        'password' => 'a-brand-new-password-123',
    ]);

    $response->assertOk();
    expect($target->fresh()->password_changed_at)->not->toBeNull();

    resetAuthGuardState();
    $this->withToken($tokenTarget)->getJson('/api/auth/me')
        ->assertStatus(401)
        ->assertJson(['error' => 'credentials_changed']);

    resetAuthGuardState();
    $this->withToken($tokenTarget)->postJson('/api/auth/refresh')
        ->assertStatus(401);
});

test("the admin's own token is unaffected when resetting someone else's password", function (): void {
    $org = Organization::factory()->create();
    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);

    resetAuthGuardState();
    $this->withToken($adminToken)->patchJson("/api/users/{$target->id}", [
        'password' => 'a-brand-new-password-123',
    ])->assertOk();

    resetAuthGuardState();
    $this->withToken($adminToken)->getJson('/api/auth/me')->assertOk();
});

test('a PATCH with only name leaves the target token valid — nothing else revokes', function (): void {
    $org = Organization::factory()->create();
    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);
    $tokenTarget = (string) auth('api')->login($target);

    resetAuthGuardState();
    $this->withToken($adminToken)->patchJson("/api/users/{$target->id}", [
        'name' => 'Renamed Only',
    ])->assertOk();

    expect($target->fresh()->password_changed_at)->toBeNull();

    resetAuthGuardState();
    $this->withToken($tokenTarget)->getJson('/api/auth/me')->assertOk();
});

test('cross-tenant admin PATCH with a password 404s and the other org user token stays valid', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $adminTokenA] = authUserAndTokenForRole($orgA, 'admin');
    $targetB = User::factory()->create(['organization_id' => $orgB->id]);
    $tokenTargetB = (string) auth('api')->login($targetB);

    resetAuthGuardState();
    $this->withToken($adminTokenA)->patchJson("/api/users/{$targetB->id}", [
        'password' => 'a-brand-new-password-123',
    ])->assertStatus(404);

    resetAuthGuardState();
    $this->withToken($tokenTargetB)->getJson('/api/auth/me')->assertOk();
});

test('an admin resetting their OWN password through the admin route is logged out, with no access_token in the body', function (): void {
    $org = Organization::factory()->create();
    ['token' => $adminToken, 'user' => $admin] = authUserAndTokenForRole($org, 'admin');

    $this->travel(2)->seconds();

    resetAuthGuardState();
    $response = $this->withToken($adminToken)->patchJson("/api/users/{$admin->id}", [
        'password' => 'a-brand-new-password-123',
    ]);

    $response->assertOk();
    expect($response->json('access_token'))->toBeNull();

    resetAuthGuardState();
    $this->withToken($adminToken)->getJson('/api/auth/me')
        ->assertStatus(401)
        ->assertJson(['error' => 'credentials_changed']);
});

test('store() sets password_changed_at with nothing to invalidate yet', function (): void {
    $org = Organization::factory()->create();
    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');

    $response = $this->withToken($adminToken)->postJson('/api/users', [
        'name' => 'Fresh User',
        'email' => 'fresh-user@example.test',
        'password' => 'a-strong-password-123',
        'role' => 'viewer',
    ]);

    $response->assertCreated();
    $created = User::where('email', 'fresh-user@example.test')->firstOrFail();
    expect($created->password_changed_at)->not->toBeNull();
});
