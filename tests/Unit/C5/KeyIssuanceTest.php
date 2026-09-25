<?php

declare(strict_types=1);

/**
 * Key issuance unit tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - generated key has 'beai_live_' prefix
 * - raw key suffix is 96 hex chars (bin2hex of 48 bytes)
 * - sha256 of raw equals what would be stored as key_hash
 * - two calls produce distinct keys
 *
 * REQ-2
 */

use App\Enums\ApiKeyMode;
use App\Services\ApiKeyGenerator;

test('generated key has beai_live_ prefix', function (): void {
    $raw = ApiKeyGenerator::generate();

    expect($raw)->toStartWith('beai_live_');
});

test('raw key suffix is 96 hex chars (48 bytes bin2hex)', function (): void {
    $raw = ApiKeyGenerator::generate();
    $suffix = substr($raw, strlen('beai_live_'));

    // bin2hex(random_bytes(48)) produces 96 hex characters
    expect(strlen($suffix))->toBe(96);
    // All characters are valid hex
    expect(ctype_xdigit($suffix))->toBeTrue();
});

test('sha256 of raw key equals the stored hash', function (): void {
    $raw = ApiKeyGenerator::generate();
    $hash = ApiKeyGenerator::hash($raw);

    expect($hash)->toBe(hash('sha256', $raw));
});

test('two generate() calls produce distinct keys', function (): void {
    $key1 = ApiKeyGenerator::generate();
    $key2 = ApiKeyGenerator::generate();

    expect($key1)->not->toBe($key2);
});

test('hash output is a 64-char lowercase hex string (SHA-256)', function (): void {
    $raw = ApiKeyGenerator::generate();
    $hash = ApiKeyGenerator::hash($raw);

    expect(strlen($hash))->toBe(64);
    expect(ctype_xdigit($hash))->toBeTrue();
});

test('generate(ApiKeyMode::Test) produces a beai_test_ prefixed key with the same entropy', function (): void {
    $raw = ApiKeyGenerator::generate(ApiKeyMode::Test);
    $suffix = substr($raw, strlen('beai_test_'));

    expect($raw)->toStartWith('beai_test_');
    expect(strlen($suffix))->toBe(96);
    expect(ctype_xdigit($suffix))->toBeTrue();
});

test('prefixOf() returns the marker plus the first 8 chars of the random part', function (): void {
    $liveKey = 'beai_live_'.str_repeat('a', 96);
    $testKey = 'beai_test_'.str_repeat('b', 96);

    expect(ApiKeyGenerator::prefixOf($liveKey))->toBe('beai_live_aaaaaaaa');
    expect(ApiKeyGenerator::prefixOf($testKey))->toBe('beai_test_bbbbbbbb');
});

// Review follow-up (public-api step 3, Part A finding 3): ApiKeyGenerator::
// modeOf() is removed — App\Enums\ApiKeyMode::fromMarker() is now the one
// place that maps a raw key back to its mode (its own recognition/null
// coverage lives in tests/Unit/C5/ApiKeyModeTest.php). This asserts the two
// classes still agree end to end: a key this generator actually produced
// round-trips through fromMarker() to the mode it was generated with.
test('a key generated for a given mode round-trips through ApiKeyMode::fromMarker() to that same mode', function (): void {
    expect(ApiKeyMode::fromMarker(ApiKeyGenerator::generate(ApiKeyMode::Live)))->toBe(ApiKeyMode::Live);
    expect(ApiKeyMode::fromMarker(ApiKeyGenerator::generate(ApiKeyMode::Test)))->toBe(ApiKeyMode::Test);
});

// ─── Review follow-up (finding 4): prefixOf() must never silently fall back
// to the live marker for a key it cannot identify — that used to happen
// because prefixOf() re-derived the marker with its own ternary instead of
// trusting modeOf()/fromMarker(). A malformed key has no marker to compute a
// prefix from, so the only honest answer is to refuse. ───────────────────────

test('prefixOf() throws InvalidArgumentException for a malformed/unrecognised key', function (): void {
    expect(fn () => ApiKeyGenerator::prefixOf('not-a-beai-key-at-all'))
        ->toThrow(InvalidArgumentException::class);
});

test('prefixOf() throws InvalidArgumentException for an empty key', function (): void {
    expect(fn () => ApiKeyGenerator::prefixOf(''))
        ->toThrow(InvalidArgumentException::class);
});
