<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Encrypts/decrypts the WHOLE `App\Http\Middleware\PublicApi\IdempotencyKey`
 * replay record before it reaches the cache store (gga round 4 finding 2).
 *
 * `POST /v1/interviews`'s replayed body carries a LIVE credential
 * (`session_token`, and the `hosted_url` built from it) — SPEC.md §3.5 and
 * G-16 both require the replay to return that EXACT original token (the
 * client must re-mint via `POST /interviews/{id}/session-tokens` if it has
 * since expired or been consumed; a replay silently minting a DIFFERENT one
 * would violate idempotency itself). That behaviour stays exactly as
 * documented — this class changes only how the record sits AT REST for up
 * to `record_ttl_seconds` (default 24h): plaintext in the configured cache
 * store before, `Crypt::encryptString()`-encrypted after.
 *
 * ONE codec for the WHOLE record (fingerprint + status + headers + body),
 * applied uniformly to EVERY route this middleware ever runs on — not a
 * per-field or per-route redaction list. `POST /v1/interviews` is the only
 * route that opts into `idempotent` today, but a future one might replay a
 * body with its own sensitive field this middleware has no way to know
 * about in advance; encrypting the whole record is the one approach that
 * stays correct without this class (or the middleware) needing to enumerate
 * "which fields are secret" per route.
 *
 * `Crypt` (Laravel's `APP_KEY`-derived encrypter, AES-256-CBC with an HMAC)
 * — the same facade every other at-rest secret in this codebase already
 * uses (e.g. `Organization::$casts['default_webhook_secret'] = 'encrypted'`)
 * — rather than a bespoke scheme: no new key material to manage, and
 * `decryptString()` already fails closed (throws) on a tampered or
 * wrong-key ciphertext.
 */
final class IdempotencyRecordCodec
{
    /**
     * @param  array{fingerprint: string, status: int, headers: array<string, string>, body: string}  $record
     */
    public static function encode(array $record): string
    {
        return Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR));
    }

    /**
     * `null` on ANY failure — a wrong/rotated `APP_KEY`, a corrupted cache
     * entry, or a pre-existing PLAINTEXT record written before this codec
     * existed. This method never crashes; what a `null` means to the
     * CALLER is that caller's own decision, not this class's — `App\Http\
     * Middleware\PublicApi\IdempotencyKey` (step 5 review follow-up, item
     * 8) refuses with `500 internal_error` rather than treating a `null`
     * here the same as a genuine cache miss, because a record existing at
     * all but failing to decode is evidence something WAS stored for this
     * key, and silently re-running the handler risks a duplicate side
     * effect — see that middleware's own docblock for the full reasoning.
     *
     * @return array{fingerprint: string, status: int, headers: array<string, string>, body: string}|null
     */
    public static function decode(string $encoded): ?array
    {
        try {
            $decrypted = Crypt::decryptString($encoded);
            $decoded = json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Broad on purpose: a wrong/rotated APP_KEY throws
            // Illuminate\Contracts\Encryption\DecryptException, malformed
            // JSON throws JsonException — both, and anything else, are
            // equally "this record cannot be trusted", never a 500 (see
            // this method's own docblock).
            return null;
        }

        if (! is_array($decoded)
            || ! isset($decoded['fingerprint'], $decoded['status'], $decoded['headers'], $decoded['body'])
            || ! is_string($decoded['fingerprint'])
            || ! is_int($decoded['status'])
            || ! is_array($decoded['headers'])
            || ! is_string($decoded['body'])
        ) {
            return null;
        }

        $headers = [];
        foreach ($decoded['headers'] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return [
            'fingerprint' => $decoded['fingerprint'],
            'status' => $decoded['status'],
            'headers' => $headers,
            'body' => $decoded['body'],
        ];
    }
}
