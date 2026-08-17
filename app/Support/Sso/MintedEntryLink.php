<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Support\Carbon;

/**
 * MintedEntryLink (operator-interview-link, design D1).
 *
 * The readonly result of `EntryLinkMinter::mint()`: the raw sso-link JWT, its
 * expiry (derived from the token's own `exp` claim — never recomputed
 * independently, so it can never drift from what the token actually carries),
 * and the resolved language used to compose the entry URL.
 *
 * REQ: Shared Entry Link Minting Logic,
 *      Entry URL Locale Prefixing Is Owned by the Minter
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */
final readonly class MintedEntryLink
{
    public function __construct(
        public string $token,
        public Carbon $expiresAt,
        public string $lang,
    ) {}
}
