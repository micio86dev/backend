<?php

declare(strict_types=1);

/**
 * Reusable interview link token generator (reusable-interview-links B1a.1,
 * design AD-1).
 *
 * The token is the ONLY credential a reusable link has: 256 bits from the
 * CSPRNG, shown once, persisted as its SHA-256 and never as itself. These tests
 * pin the wire format (`beai_rl_` + 43 base64url characters), the hash and
 * visible-prefix derivations, and above all the recognition predicate that the
 * rate limiter and the public redemption endpoint both lean on: it must refuse
 * everything that is not exactly a generated token, including the values PCRE's
 * `$` would quietly let through (a trailing newline).
 */

use App\Services\ReusableLinkTokenGenerator;

const REUSABLE_LINK_MARKER = 'beai_rl_';

/**
 * Decode the 43-char base64url body of a generated token back to bytes.
 */
function reusableLinkTokenBytes(string $token): string|false
{
    return base64_decode(strtr(substr($token, strlen(REUSABLE_LINK_MARKER)), '-_', '+/').'=', true);
}

test('a generated token is the marker plus 43 base64url characters (51 in total)', function (): void {
    $token = ReusableLinkTokenGenerator::generate();

    expect($token)->toMatch('/^beai_rl_[A-Za-z0-9_-]{43}\z/');
    expect(strlen($token))->toBe(51);
    expect($token)->toStartWith(ReusableLinkTokenGenerator::MARKER);
});

test('the body carries exactly 32 random bytes (256 bits) and no padding', function (): void {
    $token = ReusableLinkTokenGenerator::generate();
    $bytes = reusableLinkTokenBytes($token);

    expect($bytes)->toBeString();
    expect(strlen((string) $bytes))->toBe(ReusableLinkTokenGenerator::RANDOM_BYTES);
    expect(ReusableLinkTokenGenerator::RANDOM_BYTES)->toBe(32);

    // Re-encoding reproduces the body byte for byte: nothing was trimmed but
    // the `=` padding, which is what makes the token URL-fragment-safe.
    expect(rtrim(strtr(base64_encode((string) $bytes), '+/', '-_'), '='))
        ->toBe(substr($token, strlen(ReusableLinkTokenGenerator::MARKER)));
});

test('a thousand generated tokens are all distinct and share no random prefix', function (): void {
    $tokens = [];
    $prefixes = [];

    for ($i = 0; $i < 1000; $i++) {
        $token = ReusableLinkTokenGenerator::generate();
        $tokens[$token] = true;
        $prefixes[ReusableLinkTokenGenerator::prefixOf($token)] = true;
    }

    // 1,000 draws from a 256-bit space colliding would be a broken generator,
    // not bad luck; the visible 8-char prefix (48 bits) collides with
    // probability ~2e-9 for 1,000 draws, so distinct prefixes prove the
    // randomness is not anchored to a constant or a clock.
    expect($tokens)->toHaveCount(1000);
    expect($prefixes)->toHaveCount(1000);
});

test('hash() is the lowercase hex SHA-256 of the whole token, marker included', function (): void {
    $token = ReusableLinkTokenGenerator::generate();
    $hash = ReusableLinkTokenGenerator::hash($token);

    expect($hash)->toBe(hash('sha256', $token));
    expect($hash)->toMatch('/^[0-9a-f]{64}\z/');
    expect($hash)->not->toBe(hash('sha256', substr($token, strlen(ReusableLinkTokenGenerator::MARKER))));
});

test('prefixOf() is the marker plus the first 8 random characters (16 in total)', function (): void {
    $token = 'beai_rl_'.'AbCdEfGh'.str_repeat('x', 35);

    expect(ReusableLinkTokenGenerator::prefixOf($token))->toBe('beai_rl_AbCdEfGh');
    expect(strlen(ReusableLinkTokenGenerator::prefixOf(ReusableLinkTokenGenerator::generate())))->toBe(16);
});

test('prefixOf() throws on a value that is not a well-formed token', function (string $malformed): void {
    expect(fn () => ReusableLinkTokenGenerator::prefixOf($malformed))
        ->toThrow(InvalidArgumentException::class);
})->with(fn (): array => [
    'empty' => [''],
    'wrong marker' => ['beai_live_'.str_repeat('a', 43)],
    'too short' => ['beai_rl_'.str_repeat('a', 42)],
]);

test('isWellFormed() accepts a generated token', function (): void {
    expect(ReusableLinkTokenGenerator::isWellFormed(ReusableLinkTokenGenerator::generate()))->toBeTrue();
});

test('isWellFormed() rejects everything that is not exactly a generated token', function (mixed $value): void {
    expect(ReusableLinkTokenGenerator::isWellFormed($value))->toBeFalse();
})->with(fn (): array => [
    'array' => [['beai_rl_'.str_repeat('a', 43)]],
    'null' => [null],
    'integer' => [42],
    'boolean' => [true],
    'empty string' => [''],
    '42 random chars' => ['beai_rl_'.str_repeat('a', 42)],
    '44 random chars' => ['beai_rl_'.str_repeat('a', 44)],
    'plus sign' => ['beai_rl_'.str_repeat('a', 42).'+'],
    'slash' => ['beai_rl_'.str_repeat('a', 42).'/'],
    'padding' => ['beai_rl_'.str_repeat('a', 42).'='],
    'api key marker' => ['beai_rk_'.str_repeat('a', 43)],
    'live key marker' => ['beai_live_'.str_repeat('a', 43)],
    'no marker' => [str_repeat('a', 51)],
    'leading space' => [' beai_rl_'.str_repeat('a', 43)],
    'trailing space' => ['beai_rl_'.str_repeat('a', 43).' '],
    'trailing newline' => ['beai_rl_'.str_repeat('a', 43)."\n"],
    'embedded newline' => ['beai_rl_'.str_repeat('a', 20)."\n".str_repeat('a', 22)],
]);

test('neither the visible prefix nor any truncation or extension of a token is well-formed', function (): void {
    $token = ReusableLinkTokenGenerator::generate();

    expect(ReusableLinkTokenGenerator::isWellFormed(ReusableLinkTokenGenerator::prefixOf($token)))->toBeFalse();
    expect(ReusableLinkTokenGenerator::isWellFormed(substr($token, 0, -1)))->toBeFalse();
    expect(ReusableLinkTokenGenerator::isWellFormed($token.'A'))->toBeFalse();
    expect(ReusableLinkTokenGenerator::isWellFormed(substr($token, 1)))->toBeFalse();
});

test('hashIfWellFormed() hashes a generated token and returns null for anything else', function (): void {
    $token = ReusableLinkTokenGenerator::generate();

    expect(ReusableLinkTokenGenerator::hashIfWellFormed($token))->toBe(ReusableLinkTokenGenerator::hash($token));
    expect(ReusableLinkTokenGenerator::hashIfWellFormed($token."\n"))->toBeNull();
    expect(ReusableLinkTokenGenerator::hashIfWellFormed([$token]))->toBeNull();
    expect(ReusableLinkTokenGenerator::hashIfWellFormed(null))->toBeNull();
    expect(ReusableLinkTokenGenerator::hashIfWellFormed(42))->toBeNull();
    expect(ReusableLinkTokenGenerator::hashIfWellFormed('beai_live_'.str_repeat('a', 96)))->toBeNull();
});
