<?php

declare(strict_types=1);

namespace App\Actions\ReusableLinks;

/**
 * How a redemption attempt ended (reusable-interview-links, design AD-9).
 *
 * Deliberately few values: the public endpoint answers an unknown, a malformed
 * and a disabled token identically, so the action never tells its caller WHICH
 * of those it was.
 */
enum RedemptionStatus
{
    /**
     * A visitor was created and a candidate credential minted.
     */
    case Redeemed;

    /**
     * No redeemable link: missing, malformed, unknown, disabled, or its project
     * is gone. One outcome for all of them, on purpose.
     */
    case NotFound;

    /**
     * A valid, ENABLED link whose project is closed or not interviewable right
     * now. Reachable only by a holder of a working token.
     */
    case Refused;

    /**
     * The visitor's email is already enrolled in the link's project. Reachable
     * ONLY with a valid, enabled link on an open project (and a valid identity),
     * so it cannot be used to probe tokens. Nothing was written, and nothing
     * about the existing participant is carried: it is never resumed.
     */
    case Duplicate;
}
