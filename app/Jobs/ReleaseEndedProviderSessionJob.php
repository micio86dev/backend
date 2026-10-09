<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Interview\ReleaseProviderSession;
use App\Models\InterviewSession;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deferred, best-effort release of the provider session of a competency that
 * handed over to the next one.
 *
 * `/end` cannot release it synchronously: the client keeps the outgoing HeyGen
 * session live until the incoming one has painted (invisible-competency-handover,
 * D5), and stopping it earlier cuts the stream the candidate is still watching.
 * The client stops its own session once the handover completes, so this job only
 * backs up a tab that closed mid-handover. `interview.provider_release_delay_seconds`
 * is the delay.
 *
 * The refs travel as ARGUMENTS and are released exactly as captured at dispatch,
 * never re-read from the row: by the time the job runs, a resume may have issued
 * a NEWER session on the same row, and that one must not be stopped. The row is
 * only consulted to see whether anything still owns a ref — when a later
 * suspend, retry or reaper has already cleared both, the release is theirs and
 * this job has nothing to do.
 *
 * Scalars only (no model, no secret), and it never fails: the outcome of `/end`
 * is long committed and a stale provider session is not worth a retry loop.
 */
final class ReleaseEndedProviderSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One attempt: the release is best-effort and swallows its own failures. */
    public int $tries = 1;

    /** Two bounded provider calls (stop, then context delete); well inside the worker timeout. */
    public int $timeout = 60;

    public function __construct(
        public readonly int $sessionId,
        public readonly int $organizationId,
        public readonly string $provider,
        public readonly ?string $providerSessionRef,
        public readonly ?string $providerContextRef,
    ) {}

    public function handle(ReleaseProviderSession $release): void
    {
        try {
            TenantContextScope::runFor($this->organizationId, function () use ($release): void {
                $session = InterviewSession::query()->find($this->sessionId);

                if ($session === null
                    || ($session->provider_session_ref === null && $session->provider_context_ref === null)) {
                    return;
                }

                $release->forRefs($this->provider, $this->providerSessionRef, $this->providerContextRef);
            });
        } catch (Throwable $e) {
            // Class only: a provider exception message may echo key material.
            Log::warning('interview.provider_release.deferred_failed', [
                'session_id' => $this->sessionId,
                'exception' => $e::class,
            ]);
        }
    }
}
