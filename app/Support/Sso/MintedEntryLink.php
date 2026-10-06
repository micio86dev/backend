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
 * the resolved language used to compose the entry URL, and whether the
 * `candidate_ref` names a reusable-link visitor.
 *
 * `$delivery` is the channel the minter ACTUALLY used, which can differ from
 * the one requested: `Emailed` is downgraded to `Returned` for a reusable-link
 * visitor, whose address is never mailed. Callers derive "was an email queued"
 * from it, so the token's lifetime and the `email_sent` answer cannot disagree.
 *
 * `$targetsReusableLinkVisitor` is the one fact the dispatching controller needs
 * to decide not to mail the address: a visitor's address is self-declared and
 * never verified. It is a flag, not a row, because the dispatch site must not
 * query participants itself.
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
        public bool $targetsReusableLinkVisitor = false,
        public LinkDelivery $delivery = LinkDelivery::Returned,
    ) {}
}
