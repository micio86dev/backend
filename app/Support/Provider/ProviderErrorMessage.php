<?php

declare(strict_types=1);

namespace App\Support\Provider;

use Illuminate\Support\Arr;

/**
 * Extracts a provider's own diagnostic message from a failed HTTP response body,
 * with the API key stripped (PR1 — diagnosability, delta spec D6).
 *
 * Shared by HeygenProvider and TavusProvider so there is exactly one place that
 * gets this security-critical redaction right.
 *
 * Order, per `@wire-source legacy-demo/src/pages/api/interview/start.ts:260-263`:
 *   1. `message` ?? `error` ?? `data.message`.
 *   2. Scalars only — a non-scalar value returns null. A nested structure is
 *      precisely where an echoed request (and therefore the key) would hide.
 *   3. Truncate to 300 chars — bounds the log line and any accidental blob.
 *   4. `str_replace($apiKey, '[REDACTED]', $message)` when `$apiKey !== ''`.
 *   5. Assert-and-drop: if the result still contains `$apiKey`, return null.
 *      Fail closed.
 *
 * The raw response body is NEVER logged in any branch — only the string this
 * method returns (or null, in which case the caller falls back to a generic
 * status-only message).
 */
final class ProviderErrorMessage
{
    private const MAX_LENGTH = 300;

    /**
     * @param  array<string, mixed>|string|null  $body  Parsed JSON body, a raw JSON
     *                                                  string, or null.
     */
    public static function extract(array|string|null $body, string $apiKey): ?string
    {
        $data = self::normalize($body);

        if ($data === null) {
            return null;
        }

        $raw = $data['message'] ?? $data['error'] ?? Arr::get($data, 'data.message');

        if (! is_scalar($raw)) {
            return null;
        }

        $message = mb_substr((string) $raw, 0, self::MAX_LENGTH);

        if ($apiKey === '') {
            return $message;
        }

        $message = str_replace($apiKey, '[REDACTED]', $message);

        // Assert-and-drop: fail closed if the key somehow survives redaction.
        if (str_contains($message, $apiKey)) {
            return null;
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     * @return array<string, mixed>|null
     */
    private static function normalize(array|string|null $body): ?array
    {
        if (is_array($body)) {
            return $body;
        }

        if (is_string($body)) {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
