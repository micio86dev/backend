<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Candidate invitation links
|--------------------------------------------------------------------------
|
| A link BEAI delivers BY EMAIL is opened when the candidate reads their inbox,
| not within half an hour, so it lives longer than a link that is only RETURNED
| to a caller (the M2M `sso-link` mint, an operator mint that queues no mail, a
| reusable-link visitor, a placeholder address), which keeps
| `CandidateTokenFactory::SSO_LINK_TTL_MINUTES` (30). The lifetime follows the
| delivery channel and is decided in one place, `EntryLinkMinter`.
|
| The value is left UNCAST on purpose: `EntryLinkMinter` validates it as an
| integer in [15, 10080] and refuses anything else, because `(int) 'abc'` is 0
| and a silent cast would turn a typo into a different lifetime instead of a
| loud failure (same doctrine as `interview.stale_after_minutes`).
*/

return [

    /*
     * Lifetime, in minutes, of an sso-link that BEAI emails: the initial
     * invitation (operator mint with the email queued, and the scheduled-start
     * sweep) and, in a later change, the evaluation-retry link.
     *
     * Single use is unchanged: the first successful exchange consumes the jti.
     * Allowed range: 15 to 10080 (7 days). Default 1440 (24 hours).
     */
    'emailed_link_ttl_minutes' => env('CANDIDATE_INVITATION_LINK_TTL_MINUTES', 1440),

];
