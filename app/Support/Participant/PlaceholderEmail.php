<?php

declare(strict_types=1);

namespace App\Support\Participant;

use Illuminate\Support\Str;

/**
 * The placeholder email conventions (reusable-interview-links, design AD-10;
 * reusable-link-visitor-identity, design VD-20 and VD-22).
 *
 * `participants.email` is NOT NULL (CLAUDE.md ruling 8, reversed 2026-09-01),
 * and `(project_id, email)` is unique. A participant with no address of a
 * person therefore carries a synthesised, deterministic, undeliverable one. Two
 * spellings exist, both derived from the participant's OWN opaque
 * `candidate_ref` and from nothing else:
 *
 *   LEGACY  `<candidate_ref>@invalid.beai.local`, written by:
 *   - rows backfilled when the column was introduced;
 *   - an sso-link minted before the column existed, which carries no `email`
 *     claim, so the exchange falls back to it;
 *   - visitors redeemed BEFORE reusable-link-visitor-identity (legacy, kept as
 *     they are): a reusable link used to create an anonymous visitor with no
 *     name or address to give. A redemption now stores the address the visitor
 *     typed and never writes a placeholder.
 *
 *   PURGED  `<sha256 hex of candidate_ref>@purged.beai.invalid`, written by the
 *   retention purge over the address of a participant past its window. It is a
 *   hash rather than the reference because a reference can be 255 characters
 *   (the legacy spelling would overflow the 255-character column) and is free
 *   text (so `<ref>@domain` is not always a valid address); a lower-case hex
 *   local part is always valid, always 84 characters, and unique per project
 *   because the reference is.
 *
 * `.local` is reserved by RFC 6762 and `.invalid` by RFC 6761 section 6.4: neither
 * resolves anywhere, so such an address can never reach a real person, and both
 * are greppable. The spelling lives here and only here: the WRITERS (the sso
 * exchange fallback, the retention purge, through `purgedSqlExpression()`) use
 * `for()` and `forPurged()`, and the READER that must never mail one (the
 * invitation job) and the validation rule use `is()`, so they can never drift
 * apart.
 *
 * `is()` started as exactly the check the invitation job always made, a
 * case-sensitive, untrimmed `str_ends_with()`. It is now case- and
 * whitespace-insensitive and covers both domains, because it also guards
 * validation (a visitor must not be able to type a placeholder) and personal
 * data deletion, and refusing more is the safe direction for the mail guard.
 */
final class PlaceholderEmail
{
    /**
     * The legacy reserved domain, including the leading at sign.
     */
    public const DOMAIN = '@invalid.beai.local';

    /**
     * The domain of an address the retention purge wrote, including the leading
     * at sign.
     */
    public const PURGED_DOMAIN = '@purged.beai.invalid';

    /**
     * The legacy placeholder address of a candidate reference.
     */
    public static function for(string $candidateRef): string
    {
        return $candidateRef.self::DOMAIN;
    }

    /**
     * The address the retention purge writes over a participant's own: the
     * SHA-256 of its candidate reference at the purged domain, 84 characters
     * whatever the reference.
     */
    public static function forPurged(string $candidateRef): string
    {
        return hash('sha256', $candidateRef).self::PURGED_DOMAIN;
    }

    /**
     * Whether `$email` is a synthesised placeholder, in either spelling, rather
     * than an address a person gave us, whatever its case or surrounding
     * whitespace.
     */
    public static function is(string $email): bool
    {
        $normalized = mb_strtolower(Str::trim($email));

        return str_ends_with($normalized, self::DOMAIN) || str_ends_with($normalized, self::PURGED_DOMAIN);
    }

    /**
     * Whether `$email` is the participant's OWN placeholder, in either spelling:
     * the one a re-issue for an existing participant may legitimately send back.
     */
    public static function isOwn(string $email, string $candidateRef): bool
    {
        return $email === self::for($candidateRef) || $email === self::forPurged($candidateRef);
    }

    /**
     * The SQL twin of {@see self::forPurged()} for the retention purge, which
     * rewrites a row from its own column without reading it into PHP. The same
     * hash, the same domain, in the one class that owns the spelling; a test
     * pins that the two agree for ASCII, multibyte and 255-character references.
     * `sha256(bytea)` exists since PostgreSQL 11.
     *
     * Typed `literal-string` on purpose: the result is spliced into raw SQL, so
     * the column name must be a code constant, never a request value.
     *
     * @param  literal-string  $refColumn  the column holding the candidate reference
     * @return literal-string
     */
    public static function purgedSqlExpression(string $refColumn = 'candidate_ref'): string
    {
        return "encode(sha256(convert_to({$refColumn}, 'UTF8')), 'hex') || '".self::PURGED_DOMAIN."'";
    }
}
