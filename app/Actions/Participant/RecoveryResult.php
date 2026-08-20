<?php

declare(strict_types=1);

namespace App\Actions\Participant;

/**
 * The readonly result of `RecoverFailedParticipant::handle()`
 * (participant-error-recovery, design D2/D6).
 *
 * On the idempotent no-op path (participant already `in_attesa`),
 * `competenciesReset` is empty and `utterancesDiscarded` is 0 — the reset
 * and delete do NOT re-run.
 */
final readonly class RecoveryResult
{
    /**
     * @param  list<string>  $competenciesReset
     */
    public function __construct(
        public string $status,
        public array $competenciesReset,
        public int $utterancesDiscarded,
    ) {}
}
