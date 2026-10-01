<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use Random\RandomException;

/**
 * Generates, recognises and hashes the secret carried by a reusable interview
 * link (reusable-interview-links, design AD-1).
 *
 * Format: `beai_rl_` + base64url(random_bytes(32)) without padding
 *   - marker: `beai_rl_` (8 chars). It is deliberately distinct from every
 *     other credential the platform issues (`beai_live_` / `beai_test_` API
 *     keys, the JWTs), so no other guard can mistake it for theirs and a
 *     secret scanner can recognise a leaked one by its prefix alone.
 *   - body: 43 url-safe characters = 256 bits from the CSPRNG. A full-entropy
 *     secret needs no salt and no slow hash (the stored value is unguessable
 *     regardless of how fast it is computed), which is why a plain SHA-256 is
 *     correct here, exactly as for `ApiKeyGenerator`.
 *
 * Only the SHA-256 of the whole token (marker included) is persisted. The raw
 * token is returned once, inside the entry URL of the creation response, and
 * must never be logged, stored or serialised anywhere else.
 *
 * Mirrors `ApiKeyGenerator`, with one addition that class never needed:
 * `isWellFormed()`. A reusable link is redeemed by an UNAUTHENTICATED public
 * endpoint and looked up by a per-link rate limiter before any row is read, so
 * "is this exactly a token this generator could have produced" has to be a
 * single, strict, database-free predicate. The pattern ends in `\z`, never
 * `$`: PCRE's `$` also matches before a trailing newline, which would let
 * `<token>\n` past the recogniser as a second, distinct spelling of the same
 * credential.
 *
 * The regex is lenient on the LAST character only in the sense that 43 base64
 * characters carry 258 bits, so four trailing values decode to the same 32
 * bytes. A non-generated spelling is simply an unknown token (it hashes to a
 * value no row holds) and is answered with the same 404 as any other.
 *
 * Randomness comes from `random_bytes()` and nothing else (pinned by an
 * architecture test): no `rand`, `mt_rand`, `uniqid`, `Str::random` or any
 * clock-derived input may ever feed this token.
 */
final class ReusableLinkTokenGenerator
{
    /**
     * Fixed leading marker of every reusable link token.
     */
    public const MARKER = 'beai_rl_';

    /**
     * Entropy of the secret: 32 bytes = 256 bits.
     */
    public const RANDOM_BYTES = 32;

    /**
     * How many characters of the random part `prefixOf()` keeps visible.
     */
    public const VISIBLE_RANDOM_CHARS = 8;

    /**
     * The complete token: marker plus the 43 base64url characters 32 bytes
     * encode to without padding. `\z`, not `$` (see class doc).
     */
    public const FORMAT = '/^beai_rl_[A-Za-z0-9_-]{43}\z/';

    /**
     * Generate a new raw reusable link token.
     *
     * @throws RandomException if the CSPRNG fails
     */
    public static function generate(): string
    {
        return self::MARKER.rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
    }

    /**
     * Hash a raw token for storage and lookup: lowercase hex SHA-256 over the
     * whole string, marker included (64 chars, `reusable_interview_links.token_hash`).
     *
     * Only the hash is ever persisted — never the raw token.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * The visible identifier stored in `reusable_interview_links.token_prefix`:
     * the `beai_rl_` marker plus the first 8 random characters (16 chars).
     *
     * For identification only (which link is this, in the backoffice list) —
     * never used to look a link up or to authenticate anything.
     *
     * A malformed value has no random part to take a prefix from, so this
     * THROWS rather than returning a prefix that would identify nothing
     * (`ApiKeyGenerator::prefixOf()` precedent). Callers that only want to
     * test recognition call `isWellFormed()` first.
     *
     * @throws InvalidArgumentException when `$rawToken` is not a well-formed token.
     */
    public static function prefixOf(string $rawToken): string
    {
        if (! self::isWellFormed($rawToken)) {
            throw new InvalidArgumentException('Cannot compute a token_prefix for a value that is not a well-formed reusable link token.');
        }

        return self::MARKER.substr($rawToken, strlen(self::MARKER), self::VISIBLE_RANDOM_CHARS);
    }

    /**
     * Whether `$value` is exactly a token this generator could have produced.
     *
     * Takes `mixed` on purpose: the value comes straight from a request body
     * or query string, where it can be an array (`link_token[]=x`), null, a
     * number or an oversized blob, and every one of those must be a plain
     * "no" rather than a type error.
     */
    public static function isWellFormed(mixed $value): bool
    {
        return is_string($value) && preg_match(self::FORMAT, $value) === 1;
    }

    /**
     * `hash()` for a well-formed token, `null` for anything else. The rate
     * limiter's entry point: it keys a per-link bucket on the hash and must
     * never build one for input that cannot be a token.
     */
    public static function hashIfWellFormed(mixed $value): ?string
    {
        if (! is_string($value) || ! self::isWellFormed($value)) {
            return null;
        }

        return self::hash($value);
    }
}
