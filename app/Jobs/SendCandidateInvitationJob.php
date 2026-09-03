<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\CandidateInvitationNotification;
use App\Support\Mail\EmailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a candidate their entry link.
 *
 * EVERYTHING IT NEEDS ARRIVES AS A SCALAR, and that is the design rather than
 * laziness. The job holds no ids and reads no rows, so it makes no
 * tenant-scoped query and cannot be the place a tenant boundary is crossed.
 * The link itself is already minted and already signed; re-deriving any of it
 * here would mean re-doing an authorization the request already performed.
 *
 * IT REFUSES TO SEND TO A PLACEHOLDER. Rows that predate the mandatory-email
 * column carry a synthesised `@invalid.beai.local` address. `.local` is
 * reserved by RFC 6762 and resolves nowhere, so sending would produce a
 * guaranteed bounce and a candidate who is never told anything — refusing
 * loudly is what puts the operator in a position to fix the row.
 *
 * A failure is LOUD. Nobody watches a queue, and the operator who pressed
 * "invite" was told the link was created — which it was.
 */
final class SendCandidateInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Marks an address the backfill synthesised rather than one a person gave
     * us. Reserved by RFC 6762 — it resolves nowhere, by design.
     */
    private const PLACEHOLDER_DOMAIN = '@invalid.beai.local';

    public function __construct(
        private readonly string $email,
        private readonly string $entryUrl,
        private readonly string $displayName,
        private readonly string $organizationName,
        private readonly string $projectName,
        private readonly string $expiresAtLabel,
        private readonly string $locale,
        /**
         * The organization's `#rrggbb`, or null for the product's own.
         *
         * A SCALAR, like everything else this job holds — it is not read back
         * from a row, so the job still makes no tenant-scoped query and there
         * is nothing here for a tenant boundary to be crossed by.
         */
        private readonly ?string $brandColor = null,
        /**
         * The organization's logo, ABSOLUTE. A scalar like everything else
         * here, so the job still reads no row and crosses no tenant boundary
         * — and absolute because a mail has no origin to resolve a path
         * against. `EmailBranding::setLogoUrl()` refuses a relative value
         * rather than rendering a broken image.
         */
        private readonly ?string $brandLogoUrl = null,
    ) {}

    /**
     * Declared explicitly, never inherited (QueuedJobRetryOwnershipArchTest).
     * Three with backoff: a transient mail-provider hiccup is worth retrying,
     * and it must not retry past the entry token's own expiry.
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

    public function timeout(): int
    {
        return 30;
    }

    public function handle(): void
    {
        if (str_ends_with($this->email, self::PLACEHOLDER_DOMAIN)) {
            Log::warning(
                'candidate invitation NOT sent: this participant predates the mandatory-email column and '
                .'carries a synthesised placeholder address. Set a real address on the participant and invite again.',
                ['email' => $this->email],
            );

            return;
        }

        // Set for the duration of this send and cleared after, so a worker
        // handling the next tenant's mail cannot inherit this one's colour.
        // CLAUDE.md ruling 10: the words are standard, the chrome is
        // per-tenant.
        $branding = app(EmailBranding::class);
        $branding->set($this->brandColor);
        // The same name the body already greets them with now also signs the
        // header and the footer, which rendered `config('app.name')` — so a
        // candidate read an invitation from their prospective employer signed
        // by our web framework.
        $branding->setOrganizationName($this->organizationName);
        $branding->setLogoUrl($this->brandLogoUrl);

        // Routed to an ADDRESS, not to a notifiable model. A Participant is not
        // a user of this system and must never become one — giving it a
        // `routeNotification` method would make every future `notify()` call a
        // candidate-facing send by default.
        Notification::route('mail', $this->email)->notify(
            (new CandidateInvitationNotification(
                $this->entryUrl,
                $this->displayName,
                $this->organizationName,
                $this->projectName,
                $this->expiresAtLabel,
            ))->locale($this->locale)
        );

        $branding->forget();
    }
}
