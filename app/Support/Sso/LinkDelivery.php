<?php

declare(strict_types=1);

namespace App\Support\Sso;

/**
 * How an sso-link reaches the candidate, which decides how long it lives.
 *
 * `Returned`: the link is handed back to a caller (the M2M `sso-link` mint, an
 * operator mint that queues no mail, a reusable-link visitor, a placeholder
 * address). The caller decides how it travels, so it keeps the short
 * `CandidateTokenFactory::SSO_LINK_TTL_MINUTES` lifetime.
 *
 * `Emailed`: BEAI itself puts the link in the candidate's inbox, where it is
 * opened hours later, so it lives `candidate_invitations.emailed_link_ttl_minutes`.
 *
 * `EntryLinkMinter` is the only place that turns a channel into a lifetime, and
 * it reports the channel it actually used on `MintedEntryLink::$delivery`, so
 * callers derive "was an email queued" from the same fact the token carries.
 */
enum LinkDelivery: string
{
    case Returned = 'returned';
    case Emailed = 'emailed';
}
