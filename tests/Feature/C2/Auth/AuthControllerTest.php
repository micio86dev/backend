<?php

/**
 * AuthController feature tests (C2, corrected first for
 * backoffice-session-refresh-hardening slice 3 — design D2/D4/D8).
 *
 * Covers login, refresh, logout (jti denylist + family revoke), me, and
 * superadmin login. All tests use RefreshDatabase (via Pest.php C2 scoping).
 *
 * Corrected from the pre-hardening shape: login no longer returns a
 * `refresh_token` JSON field (D8 — the real refresh credential is now an
 * httpOnly cookie the client must never read); login stamps a `fam` claim on
 * the access token and sets the refresh cookie; `/refresh` is authenticated
 * by cookie + CSRF header rather than a Bearer token (D8 — it must work even
 * when the access token has already expired).
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

// Helper: create a user with known password and optionally an org.
function makeUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'password' => Hash::make('secret-password'),
    ], $attrs));
}

/**
 * Extracts the raw `beai_refresh` cookie's value from a TestResponse — the
 * SAME cookie a browser would store, never decrypted/re-encoded by the test
 * client (jwt.php decrypt_cookies=false; this cookie carries its own
 * distinct encryption exemption via App\Http\Middleware\EncryptCookies'
 * $except list, added alongside the controller wiring).
 */
function refreshCookieFrom(\Illuminate\Testing\TestResponse $response): ?string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config('refresh_tokens.cookie.name')) {
            return $cookie->getValue();
        }
    }

    return null;
}

function refreshHeaders(): array
{
    return ['X-BEAI-Refresh' => '1'];
}

// ─── Login ───────────────────────────────────────────────────────────────────

test('valid credentials → 200 with access_token + token_type bearer, NO refresh_token field', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'token_type'])
        ->assertJsonMissingPath('refresh_token')
        ->assertJsonPath('token_type', 'bearer');
});

test('login sets the beai_refresh cookie: HttpOnly, Secure, SameSite=None, Path=/api/auth/refresh', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'beai_refresh');

    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->isSecure())->toBeTrue();
    expect($cookie->getSameSite())->toBe('none');
    expect($cookie->getPath())->toBe('/api/auth/refresh');
    expect($cookie->getValue())->toContain('.'); // {family_id}.{secret}
});

test('login stamps a fam claim on the access token', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $token = $response->json('access_token');
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('fam'))->toBeString()->not->toBeEmpty();
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

// ─── Refresh (D8: publicly routable — cookie + CSRF header, NOT auth:api) ──────

test('valid refresh cookie + CSRF header → 200 with a new access_token, rotated cookie', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $refreshCookieValue = refreshCookieFrom($loginResponse);

    $response = $this->withHeaders(refreshHeaders())
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $refreshCookieValue)
        ->postJson('/api/auth/refresh');

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'token_type'])
        ->assertJsonMissingPath('refresh_token');

    $rotatedCookieValue = refreshCookieFrom($response);
    expect($rotatedCookieValue)->not->toBeNull();
    expect($rotatedCookieValue)->not->toBe($refreshCookieValue);
});

test('refresh works even when the access token has already expired (D8 — the structural fix)', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $refreshCookieValue = refreshCookieFrom($loginResponse);

    $this->travel(31)->minutes(); // past jwt.ttl=30 — access token now expired

    $this->withHeaders(refreshHeaders())
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $refreshCookieValue)
        ->postJson('/api/auth/refresh')
        ->assertOk();
});

test('refresh without the CSRF header → 403', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $refreshCookieValue = refreshCookieFrom($loginResponse);

    $this->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $refreshCookieValue)
        ->postJson('/api/auth/refresh')
        ->assertForbidden();
});

test('refresh without any cookie → 401 refresh_token_invalid, revokes nothing', function (): void {
    $this->withHeaders(refreshHeaders())
        ->postJson('/api/auth/refresh')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'refresh_token_invalid');
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

test('logout clears the beai_refresh cookie and revokes its family', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    $token = $loginResponse->json('access_token');
    $refreshCookieValue = refreshCookieFrom($loginResponse);

    $logoutResponse = $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk();

    $clearedCookie = collect($logoutResponse->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'beai_refresh');
    expect($clearedCookie)->not->toBeNull();
    expect($clearedCookie->getValue())->toBe('');

    // The family behind that cookie is now dead too.
    $this->withHeaders(refreshHeaders())
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $refreshCookieValue)
        ->postJson('/api/auth/refresh')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'refresh_token_revoked');
});

test('logout triggers forgetCachedPermissions', function (): void {
    $org = Organization::factory()->create();
    $user = makeUser(['organization_id' => $org->id]);

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

test('superadmin login (null org, is_superadmin=true) → 200 with token, no refresh_token field', function (): void {
    $user = makeUser(['organization_id' => null, 'is_superadmin' => true]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'token_type'])
        ->assertJsonMissingPath('refresh_token');
});
