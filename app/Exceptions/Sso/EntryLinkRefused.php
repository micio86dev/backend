<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use RuntimeException;

/**
 * Thrown by `EntryLinkMinter::mint()` when the mint decision refuses a
 * candidate entry link (operator-interview-link, design D1).
 *
 * Carries a closed-set `reason` (`gates` | `role_code` | `terminal`) and, for
 * `role_code`, the specific validation message — never an HTTP status. Both
 * callers (`SsoLinkController`, `EntryLinkController`) catch this and rebuild
 * their OWN literal response: Scramble infers each controller's request/
 * response schema from the literal `response()->json([...])` calls at each
 * call site, so the refusal itself must carry no HTTP shape (design D1).
 *
 * REQ: Shared Entry Link Minting Logic,
 *      A gate refusal reason is consistent across both mints
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */
final class EntryLinkRefused extends RuntimeException
{
    public function __construct(
        public readonly EntryLinkRefusalReason $reason,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $reason->value);
    }
}
