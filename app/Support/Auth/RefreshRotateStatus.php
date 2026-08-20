<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Outcome of App\Support\Auth\RefreshTokenStore::rotate() (D2/D6).
 */
enum RefreshRotateStatus
{
    /** Normal rotation: a fresh generation was issued, cookie must be re-set. */
    case Rotated;

    /** Two tabs raced within the grace window (D6): fresh access token, no rotation, no Set-Cookie. */
    case ConcurrentDuplicate;

    /** Unknown/garbage token. Step 4b (D2) is load-bearing: nothing is revoked. */
    case Invalid;

    /** Token maps to a family that no longer exists (prior logout or reuse kill). */
    case Revoked;

    /** The family's absolute 14-day ceiling has passed. */
    case Expired;

    /** A superseded generation was replayed outside the concurrency grace — the whole family is revoked. */
    case Reused;
}
