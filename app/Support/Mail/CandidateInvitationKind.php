<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Jobs\SendCandidateInvitationJob;

/**
 * Which transactional candidate invitation a {@see SendCandidateInvitationJob}
 * delivers.
 *
 * `Initial` is the first invitation to an interview. `Retry` is the single
 * re-interview of a `pending` evaluation (scoring-retry-rt-b): same layout,
 * same requirements and link lines, only the subject and the introduction
 * differ. String-backed so it serializes into the queued job payload as a
 * scalar, like everything else that job holds.
 *
 * Both kinds are static copy in `lang/{it,en}/candidate_invitation.php`
 * (CLAUDE.md ruling 10): neither is editable by a tenant.
 */
enum CandidateInvitationKind: string
{
    case Initial = 'initial';
    case Retry = 'retry';
}
