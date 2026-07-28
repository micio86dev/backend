<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * WebhookSigner — HMAC-SHA256 request signing (C10 — Webhooks Integration, design.md D6).
 *
 * Signed string: "{unix_timestamp}.{raw_json_body}" — the EXACT bytes transmitted.
 * `encode()` is the single, canonical source of those bytes: it is the ONLY place in
 * the codebase allowed to turn a payload array into wire bytes. Every caller MUST sign
 * and transmit `encode()`'s output — never re-encode the array separately (e.g. via
 * `Http::post($url, $array)`), which would silently produce different bytes than what
 * was signed and break verification for every receiver (design.md D6, hard rule).
 *
 * REQ: HMAC signature scheme (C10 D6)
 */
final class WebhookSigner
{
    /**
     * Canonically encode a payload array to the exact bytes that MUST be both signed
     * and transmitted. JSON_UNESCAPED_SLASHES/JSON_UNESCAPED_UNICODE are required for
     * byte-stability — the default json_encode() flags escape both, which a receiver's
     * independent HMAC verification would not reproduce unless it used the identical
     * flags, and BEAI does not control the receiver's implementation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Sign "{timestamp}.{rawBody}" with HMAC-SHA256, lowercase hex digest.
     *
     * $rawBody MUST be the exact bytes that will be transmitted — normally the output
     * of encode() called on the same payload, never a separately re-encoded array.
     */
    public function sign(int $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Format the X-BEAI-Signature header value: "v1={hex}".
     */
    public function header(string $hex): string
    {
        return 'v1='.$hex;
    }

    /**
     * Constant-time verification of a provided hex digest against a freshly computed
     * one — mirrors what an independent receiver-side verifier must do.
     */
    public function verify(int $timestamp, string $rawBody, string $secret, string $providedHex): bool
    {
        return hash_equals($this->sign($timestamp, $rawBody, $secret), $providedHex);
    }
}
