<?php

declare(strict_types=1);

namespace App\Support\Demo;

use RuntimeException;

/**
 * The placeholder image `beai:demo-seed` writes for every demo snapshot
 * (design D6).
 *
 * `ext-gd` and `imagick` are not present in the production image
 * (`Dockerfile:14,49` installs `pdo_pgsql zip opcache pcntl posix redis`
 * only), so a JPEG cannot be generated at seed time. This is a ~700-byte,
 * 64×64 mid-grey JPEG authored once (Pillow, quality 50) and committed as a
 * base64 string — text in a PHP file, diffable and reviewable, with no binary
 * blob in the repository and no image extension required by the runtime.
 *
 * Key scheme parity: the decoded bytes are written verbatim with
 * `Storage::put()` (no disk argument) at the same key shape the candidate
 * upload path uses (`SnapshotController.php:106-111`):
 * `{organization_id}/{participant_id}/{interview_session_id}/{uuid}.jpg`.
 */
final class PlaceholderJpeg
{
    /**
     * A 64×64 mid-grey JPEG, quality 50. Authored once; never regenerated at
     * runtime.
     */
    private const BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/2wBDARESEhgVGC8aGi9jQjhCY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2P/wAARCABAAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooA/9k=';

    /**
     * Decode the placeholder to raw JPEG bytes, asserting the same magic-byte
     * invariant `SnapshotController::store()` enforces on every candidate
     * upload (first three bytes 0xFF 0xD8 0xFF).
     *
     * @throws RuntimeException if the embedded constant is ever corrupted.
     */
    public static function decode(): string
    {
        $decoded = base64_decode(self::BASE64, strict: true);

        if ($decoded === false || strlen($decoded) < 3) {
            throw new RuntimeException('PlaceholderJpeg: embedded base64 constant failed to decode.');
        }

        if (ord($decoded[0]) !== 0xFF || ord($decoded[1]) !== 0xD8 || ord($decoded[2]) !== 0xFF) {
            throw new RuntimeException('PlaceholderJpeg: decoded bytes do not start with the JPEG magic bytes.');
        }

        return $decoded;
    }
}
