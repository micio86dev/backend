<?php

declare(strict_types=1);

namespace App\Actions\Participant;

use Illuminate\Support\Carbon;

/**
 * The readonly result of `AuthorizeEvaluationRetry::handle()`
 * (scoring-retry-rt-b, design D4).
 *
 * `entryUrl` is the single-use link minted inside the authorization
 * transaction. It is returned to the authorizer once and never stored, so it
 * cannot be shown again. `expiresAt` is read back from the token's own `exp`.
 * `emailSent` says whether BEAI queued the link by email (false for a
 * placeholder address or a reusable-link visitor); `competenciesReset` lists
 * the competency codes whose sessions were reset for the re-interview.
 */
final readonly class RetryAuthorization
{
    /**
     * @param  list<string>  $competenciesReset
     */
    public function __construct(
        public string $status,
        public string $entryUrl,
        public Carbon $expiresAt,
        public bool $emailSent,
        public array $competenciesReset,
    ) {}
}
