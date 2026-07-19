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
