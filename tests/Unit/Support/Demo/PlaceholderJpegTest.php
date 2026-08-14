<?php

declare(strict_types=1);

/**
 * PlaceholderJpeg — the demo snapshot payload (design D6).
 *
 * `ext-gd` and `imagick` are NOT in the production image (Dockerfile:14,49 —
 * pdo_pgsql zip opcache pcntl posix redis only), so the bytes cannot be
 * generated at runtime. A base64 constant is text in a PHP file — diffable,
 * reviewable, and requires no extension — decoded once per snapshot write.
 *
 * The magic-byte assertion mirrors the check
 * `SnapshotController.php:95-99` applies to every candidate upload: the first
 * three bytes must be 0xFF 0xD8 0xFF.
 */

use App\Support\Demo\PlaceholderJpeg;

test('the decoded bytes start with the JPEG magic bytes', function (): void {
    $decoded = PlaceholderJpeg::decode();

    expect(strlen($decoded))->toBeGreaterThan(3);
    expect(ord($decoded[0]))->toBe(0xFF);
    expect(ord($decoded[1]))->toBe(0xD8);
    expect(ord($decoded[2]))->toBe(0xFF);
});

test('decoding twice returns byte-identical content', function (): void {
    expect(PlaceholderJpeg::decode())->toBe(PlaceholderJpeg::decode());
});
