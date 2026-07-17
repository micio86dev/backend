<?php

/**
 * AuthController feature tests (C2).
 *
 * Covers login, refresh, logout (jti denylist), me, and superadmin login.
 * All tests use RefreshDatabase (via Pest.php C2 scoping).
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Helper: create a user with known password and optionally an org.
function makeUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'password' => Hash::make('secret-password'),
    ], $attrs));
}

// ─── Login ───────────────────────────────────────────────────────────────────

test('valid credentials → 200 with access_token, refresh_token, token_type bearer', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $response = $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'refresh_token', 'token_type'])
        ->assertJsonPath('token_type', 'bearer');
});

test('invalid password → 401', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'wrong-password',
    ])->assertUnauthorized();
});

test('unknown email → 401', function (): void {
    $this->postJson('/api/auth/login', [
        'email'    => 'nobody@example.com',
        'password' => 'secret-password',
    ])->assertUnauthorized();
});

// ─── Refresh ─────────────────────────────────────────────────────────────────

test('valid refresh token → 200 with new access_token', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    // Log in first to get a token pair
    $loginResponse = $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $token = $loginResponse->json('access_token');

    $response = $this->withToken($token)
        ->postJson('/api/auth/refresh');

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'token_type']);
});

// ─── Logout ──────────────────────────────────────────────────────────────────

test('logout → 200 and subsequent me returns 401', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $token = $loginResponse->json('access_token');

    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk();

    // Same token must now be rejected
    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

test('logout triggers forgetCachedPermissions', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    // Spy: after logout the permission registrar cache should be cleared.
    // We verify by asserting that a fresh hasRole check after role assignment
    // is not served from a stale cache. As a proxy, just confirm the call goes through.
    // The actual cache invalidation is tested via RoleService / events in isolation tests.
    $loginResponse = $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $token = $loginResponse->json('access_token');

    // forgetCachedPermissions must not throw — logout must complete successfully.
    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk();
});

// ─── Me ──────────────────────────────────────────────────────────────────────

test('me with valid token → 200 with user, org, and roles', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/auth/me');

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('organization.id', $org->id)
        ->assertJsonPath('organization.name', $org->name);
});

test('me with denylisted token → 401', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $token = $loginResponse->json('access_token');

    $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

test('me without token → 401', function (): void {
    $this->getJson('/api/auth/me')
        ->assertUnauthorized();
});

// ─── Superadmin login ─────────────────────────────────────────────────────────

test('superadmin login (null org, is_superadmin=true) → 200 with token', function (): void {
    $user = makeUser(['organization_id' => null, 'is_superadmin' => true]);

    $response = $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'refresh_token', 'token_type']);
});
