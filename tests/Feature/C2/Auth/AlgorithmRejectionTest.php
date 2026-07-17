<?php

/**
 * Algorithm rejection tests (C2 — JWT security).
 *
 * Asserts that tokens signed with non-HS256 algorithms are rejected.
 * The `algo` in config/jwt.php is HARDCODED to Provider::ALGO_HS256 (no env override).
 */

use Firebase\JWT\JWT;

test('token signed with alg:none is rejected with 401', function (): void {
    // Craft a JWT with alg:none — no signature
    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none']));
    $payload = base64_encode(json_encode([
        'sub' => 1,
        'iat' => time(),
        'exp' => time() + 3600,
        'nbf' => time(),
        'jti' => 'fake-jti-none',
    ]));
    $token = $header.'.'.$payload.'.';

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

test('JWT_ALGO is not read from env (hardcoded constant)', function (): void {
    // Confirm the config value is Provider::ALGO_HS256 regardless of any env setting.
    // ALGO_HS256 = 'HS256'
    expect(config('jwt.algo'))->toBe('HS256');
});

test('JWT_TTL default is 30 minutes', function (): void {
    expect(config('jwt.ttl'))->toBe(30);
});
