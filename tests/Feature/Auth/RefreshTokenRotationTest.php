<?php

/**
 * RED — 3.2: full HTTP rotation/reuse/CSRF/cookie contract
 * (backoffice-session-refresh-hardening design D2/D4/D5/D6/D8).
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function rotationTestUser(): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create([
        'organization_id' => $org->id,
        'password' => Hash::make('secret-password'),
    ]);

    return [$org, $user];
}

function loginAndGetRefreshCookie(User $user): string
{
    $response = test()->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config('refresh_tokens.cookie.name')) {
            return $cookie->getValue();
        }
    }

    throw new RuntimeException('No refresh cookie on login response.');
}

// ─── Full rotation chain ────────────────────────────────────────────────────

test('rotation chain: three successive legitimate rotations each mint a distinct cookie', function (): void {
    [, $user] = rotationTestUser();
    $cookieA = loginAndGetRefreshCookie($user);

    $responseB = $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieA)
        ->postJson('/api/auth/refresh')
        ->assertOk();

    $cookieB = collect($responseB->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('refresh_tokens.cookie.name'))
        ->getValue();

    expect($cookieB)->not->toBe($cookieA);

    $responseC = $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieB)
        ->postJson('/api/auth/refresh')
        ->assertOk();

    $cookieC = collect($responseC->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('refresh_tokens.cookie.name'))
        ->getValue();

    expect($cookieC)->not->toBe($cookieB)->not->toBe($cookieA);
});

test('a cookie replayed once (generation exactly one behind, within grace) is a concurrent duplicate, not reuse', function (): void {
    [, $user] = rotationTestUser();
    $cookieA = loginAndGetRefreshCookie($user);

    $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieA)
        ->postJson('/api/auth/refresh')
        ->assertOk();

    // Presenting the SAME already-consumed cookieA a second time, immediately,
    // is indistinguishable from a genuine two-tab race (D6) — mints a token,
    // does NOT revoke the family.
    $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieA)
        ->postJson('/api/auth/refresh')
        ->assertOk()
        ->assertJsonMissingPath('refresh_token');
});

test('a cookie replayed after TWO further rotations (generation gap > 1) is reuse even with zero elapsed time', function (): void {
    [, $user] = rotationTestUser();
    $cookieA = loginAndGetRefreshCookie($user);

    $cookieB = collect(
        $this->withHeaders(['X-BEAI-Refresh' => '1'])
            ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieA)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->headers->getCookies()
    )->first(fn ($c) => $c->getName() === config('refresh_tokens.cookie.name'))->getValue();

    $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieB)
        ->postJson('/api/auth/refresh')
        ->assertOk();

    // cookieA's generation (0) is now TWO behind the family's current
    // generation (2) — the "exactly one behind" gate fails regardless of
    // elapsed time, proving the generation check (not just the clock) guards
    // the concurrency exception.
    $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieA)
        ->postJson('/api/auth/refresh')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'refresh_token_reused');
});

test('replay of a superseded cookie revokes the ENTIRE family — the rotated cookie is also dead afterward', function (): void {
    [, $user] = rotationTestUser();
    $cookieA = loginAndGetRefreshCookie($user);

    $responseB = $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieA)
        ->postJson('/api/auth/refresh')
        ->assertOk();

    $cookieB = collect($responseB->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('refresh_tokens.cookie.name'))
        ->getValue();

    $this->travel(11)->seconds();

    $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieA)
        ->postJson('/api/auth/refresh')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'refresh_token_reused');

    $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookieB)
        ->postJson('/api/auth/refresh')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'refresh_token_revoked');
});

// ─── CSRF ────────────────────────────────────────────────────────────────────

test('missing the X-BEAI-Refresh header → 403, family untouched', function (): void {
    [, $user] = rotationTestUser();
    $cookie = loginAndGetRefreshCookie($user);

    $this->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookie)
        ->postJson('/api/auth/refresh')
        ->assertForbidden();

    // The family survived the rejected attempt — a real refresh still works.
    $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookie)
        ->postJson('/api/auth/refresh')
        ->assertOk();
});

test('a disallowed Origin header on a non-preflight request → 403', function (): void {
    [, $user] = rotationTestUser();
    $cookie = loginAndGetRefreshCookie($user);

    $this->withHeaders(['X-BEAI-Refresh' => '1', 'Origin' => 'https://evil.example.com'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookie)
        ->postJson('/api/auth/refresh')
        ->assertForbidden();
});

test('an allowlisted Origin header on a non-preflight request is accepted', function (): void {
    [, $user] = rotationTestUser();
    $cookie = loginAndGetRefreshCookie($user);

    $this->withHeaders(['X-BEAI-Refresh' => '1', 'Origin' => 'http://localhost:3001'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookie)
        ->postJson('/api/auth/refresh')
        ->assertOk();
});

test('preflight for /api/auth/refresh: allowed origin gets ACAO, unlisted origin does not', function (): void {
    $this->withHeaders([
        'Origin' => 'http://localhost:3001',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'X-BEAI-Refresh',
    ])->options('/api/auth/refresh')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3001');

    $this->withHeaders([
        'Origin' => 'https://evil.example.com',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'X-BEAI-Refresh',
    ])->options('/api/auth/refresh')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

// ─── Raw Set-Cookie flags AND Path ──────────────────────────────────────────

test('raw Set-Cookie on refresh carries every required flag AND the narrow Path', function (): void {
    [, $user] = rotationTestUser();
    $cookie = loginAndGetRefreshCookie($user);

    $response = $this->withHeaders(['X-BEAI-Refresh' => '1'])
        ->withCredentials()->withUnencryptedCookie(config('refresh_tokens.cookie.name'), $cookie)
        ->postJson('/api/auth/refresh')
        ->assertOk();

    $setCookieHeader = collect($response->headers->all('Set-Cookie'))
        ->first(fn (string $raw) => str_starts_with($raw, 'beai_refresh='));

    $lower = mb_strtolower($setCookieHeader);

    expect($setCookieHeader)->not->toBeNull();
    expect($lower)->toContain('httponly');
    expect($lower)->toContain('secure');
    expect($lower)->toContain('samesite=none');
    expect($lower)->toContain('path=/api/auth/refresh');
    expect($lower)->not->toContain('path=/;');
});

// ─── Login response contract ────────────────────────────────────────────────

test('login response has NO refresh_token key anywhere in the JSON body', function (): void {
    [, $user] = rotationTestUser();

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    expect($response->json())->not->toHaveKey('refresh_token');
});
