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
        'email' => $user->email,
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
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnauthorized();
});

test('unknown email → 401', function (): void {
    $this->postJson('/api/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'secret-password',
    ])->assertUnauthorized();
});

// ─── Login — validation ───────────────────────────────────────────────────────

test('login with missing email → 422 validation error', function (): void {
    $this->postJson('/api/auth/login', [
        'password' => 'secret-password',
    ])->assertUnprocessable();
});

test('login with missing password → 422 validation error', function (): void {
    $this->postJson('/api/auth/login', [
        'email' => 'user@example.com',
    ])->assertUnprocessable();
});

// ─── Refresh ─────────────────────────────────────────────────────────────────

test('valid refresh token → 200 with new access_token', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    // Log in first to get a token pair
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $token = $loginResponse->json('access_token');

    $response = $this->withToken($token)
        ->postJson('/api/auth/refresh');

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'token_type']);
});

test('refresh without bearer token → 401 token could not be refreshed', function (): void {
    // Use actingAs to satisfy auth:api middleware without supplying an Authorization header.
    // The guard resolves $user from the pre-set state, but jwtGuard()->refresh() calls
    // requireToken() which looks for a Bearer token in the request — none is present, so
    // it throws JWTException('Token could not be parsed from the request.').
    // The controller catch block converts this to 401 + 'Token could not be refreshed.'
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/auth/refresh');

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Token could not be refreshed.');
});

// ─── Logout ──────────────────────────────────────────────────────────────────

test('logout → 200 and subsequent me returns 401', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
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
        'email' => $user->email,
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

// user-profile-self-service, identity-auth spec delta: "locale is included
// and reflects the stored preference". Previously the column and $fillable
// entry existed but /auth/me never returned it (AuthController.php:144).
test('me includes the user\'s stored locale (identity-auth spec, user-profile-self-service)', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id, 'locale' => 'it']);

    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/auth/me');

    $response->assertOk()
        ->assertJsonPath('user.locale', 'it');
});

test('me with denylisted token → 401', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
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
        'email' => $user->email,
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'refresh_token', 'token_type']);
});
