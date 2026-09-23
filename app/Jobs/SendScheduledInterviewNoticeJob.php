<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\CandidateInterviewNoticeNotification;
use App\Support\Mail\EmailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a scheduled candidate their advance notice (interview-scheduling,
 * design AD-9).
 *
 * EVERYTHING IT NEEDS ARRIVES AS A SCALAR — the exact same discipline
 * `SendCandidateInvitationJob` documents for itself, and for the identical
 * reason: the job holds no ids and reads no rows, so it makes no
 * tenant-scoped query and cannot be the place a tenant boundary is crossed.
 * `App\Console\Commands\DispatchScheduledInterviewInvitations` resolves
 * every value under its own `TenantContextScope::runFor()` block before
 * dispatching this job.
 *
 * Allowlisted (not TenantContextScope-referencing) in
 * `tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php`, with the same
 * justification already written there for `SendCandidateInvitationJob`.
 */
final class SendScheduledInterviewNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $email,
        private readonly string $displayName,
        private readonly string $organizationName,
        private readonly string $projectName,
        private readonly string $locale,
        /**
         * The organization's `#rrggbb`, or null for the product's own — a
         * SCALAR, like everything else this job holds.
         */
        private readonly ?string $brandColor = null,
        /**
         * The organization's logo, ABSOLUTE — a scalar like everything else
         * here, for the same reason `SendCandidateInvitationJob` requires it
         * absolute: a mail has no origin to resolve a relative path against.
         */
        private readonly ?string $brandLogoUrl = null,
    ) {}

    /**
     * Declared explicitly, never inherited (QueuedJobRetryOwnershipArchTest).
     * Same 3-attempt/backoff shape as `SendCandidateInvitationJob`: a
     * transient mail-provider hiccup is worth retrying.
     */
    public function tries(): int
    {
        return 3;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * A PROPERTY, never a `timeout()` method — see
     * `SendCandidateInvitationJob::$timeout`'s own docblock for the exact
     * framework-attribute-reading reason a method form is silently ignored.
     */
    public int $timeout = 30;

    public function handle(): void
    {
        // Set for the duration of this send and cleared after, so a worker
        // handling the next tenant's mail cannot inherit this one's colour.
        // CLAUDE.md ruling 10: the words are standard, the chrome is
        // per-tenant.
        $branding = app(EmailBranding::class);
        $branding->set($this->brandColor);
        $branding->setOrganizationName($this->organizationName);
        $branding->setLogoUrl($this->brandLogoUrl);

        // try/finally, not a trailing forget(): a worker is a long-lived
        // process handling one tenant's mail after another's, and a throw
        // from notify() (mail transport error, template failure) must never
        // leave THIS tenant's colour set for whatever job that worker picks
        // up next.
        try {
            // Routed to an ADDRESS, not to a notifiable model — same
            // reasoning as SendCandidateInvitationJob: a Participant is not a
            // user of this system and must never become one.
            Notification::route('mail', $this->email)->notify(
                (new CandidateInterviewNoticeNotification(
                    $this->displayName,
                    $this->organizationName,
                    $this->projectName,
                ))->locale($this->locale)
            );
        } finally {
            $branding->forget();
        }
    }
}
