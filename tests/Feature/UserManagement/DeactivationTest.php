<?php

declare(strict_types=1);

/**
 * Soft deactivation, not hard delete (backoffice-missing-pages D5).
 *
 * `deactivated_at` is set/cleared, the row always survives, and a
 * deactivated user holding a still-valid JWT is rejected at the NEXT
 * authenticated request — not merely at their next login.
 *
 * REQ: Soft Deactivation, Not Hard Delete
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\User;

test('deactivate sets the marker without removing the row; the user cannot then log in', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id, 'email' => 'to-deactivate@example.test']);

    $response = $this->withToken($token)->postJson("/api/users/{$target->id}/deactivate");

    $response->assertNoContent();
    $fresh = $target->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deactivated_at)->not->toBeNull();

    $this->postJson('/api/auth/login', [
        'email' => 'to-deactivate@example.test',
        'password' => 'password',
    ])->assertUnauthorized();
});

test('activate reverses a deactivation and the user can log in again', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create([
        'organization_id' => $org->id,
        'email' => 'to-reactivate@example.test',
        'deactivated_at' => now(),
    ]);

    $response = $this->withToken($token)->postJson("/api/users/{$target->id}/activate");

    $response->assertNoContent();
    expect($target->fresh()->deactivated_at)->toBeNull();

    $this->postJson('/api/auth/login', [
        'email' => 'to-reactivate@example.test',
        'password' => 'password',
    ])->assertOk();
});

test('a deactivated user holding a still-valid JWT is rejected on the next request, not just at login', function (): void {
    $org = Organization::factory()->create();
    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');
    ['user' => $target, 'token' => $targetToken] = authUserAndTokenForRole($org, 'viewer');

    // Token minted while active — proven usable before deactivation.
    resetAuthGuardState();
    $this->withToken($targetToken)->getJson('/api/auth/me')->assertOk();

    resetAuthGuardState();
    $this->withToken($adminToken)->postJson("/api/users/{$target->id}/deactivate")->assertNoContent();

    // Same still-unexpired token — must now be rejected with the
    // machine-readable account_deactivated code, not a generic 401.
    resetAuthGuardState();
    $response = $this->withToken($targetToken)->getJson('/api/auth/me');
    $response->assertForbidden();
    expect($response->json('error'))->toBe('account_deactivated');
});

test('a deactivated user is rejected on /auth/refresh too', function (): void {
    $org = Organization::factory()->create();
    ['token' => $adminToken] = authUserAndTokenForRole($org, 'admin');
    ['user' => $target, 'token' => $targetToken] = authUserAndTokenForRole($org, 'viewer');

    resetAuthGuardState();
    $this->withToken($adminToken)->postJson("/api/users/{$target->id}/deactivate")->assertNoContent();

    resetAuthGuardState();
    $response = $this->withToken($targetToken)->postJson('/api/auth/refresh');
    $response->assertForbidden();
    expect($response->json('error'))->toBe('account_deactivated');
});
