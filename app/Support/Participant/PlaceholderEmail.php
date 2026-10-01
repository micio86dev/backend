<?php

declare(strict_types=1);

namespace App\Support\Participant;

/**
 * The placeholder email convention (reusable-interview-links, design AD-10).
 *
 * `participants.email` is NOT NULL (CLAUDE.md ruling 8, reversed 2026-09-01),
 * and `(project_id, email)` is unique. A participant with no address of a
 * person therefore carries a synthesised, deterministic, undeliverable one:
 * `<candidate_ref>@invalid.beai.local`. Three kinds of row use it:
 *
 *   - rows backfilled when the column was introduced;
 *   - an sso-link minted before the column existed, which carries no `email`
 *     claim, so the exchange falls back to it;
 *   - visitors redeemed BEFORE reusable-link-visitor-identity (legacy, kept as
 *     they are): a reusable link used to create an anonymous visitor with no
 *     name or address to give. A redemption now stores the address the visitor
 *     typed and never writes a placeholder.
 *
 * `.local` is reserved by RFC 6762 and resolves nowhere, so such an address can
 * never reach a real person, and it is greppable. The spelling lives here and
 * only here: the WRITER (the sso exchange fallback, now its only one) calls
 * `for()`, and the READER that must never mail one (the invitation job) calls
 * `is()`, so the two can never drift apart.
 *
 * `is()` is deliberately exactly the check the invitation job always made, a
 * case-sensitive, untrimmed `str_ends_with()`: this class was extracted without
 * changing behaviour.
 */
final class PlaceholderEmail
{
    /**
     * The reserved domain, including the leading at sign.
     */
    public const DOMAIN = '@invalid.beai.local';

    /**
     * The placeholder address of a candidate reference.
     */
    public static function for(string $candidateRef): string
    {
        return $candidateRef.self::DOMAIN;
    }

    /**
     * Whether `$email` is a synthesised placeholder rather than an address a
     * person gave us.
     */
    public static function is(string $email): bool
    {
        return str_ends_with($email, self::DOMAIN);
    }
}
