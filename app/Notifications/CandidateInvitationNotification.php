<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Mail\CandidateInvitationKind;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation a candidate receives for one assessment.
 *
 * A PURE RENDERER. It MUST NOT implement `ShouldQueue` —
 * `tests/Arch/Tenancy/NotificationNeverQueuedArchTest.php` forbids it for every
 * class under `app/Notifications/`. The queue boundary is
 * `SendCandidateInvitationJob`.
 *
 * IT IS ADDRESSED, NOT BROADCAST. Sent with `Notification::route()` to one
 * address rather than to a notifiable model, because a Participant is not a
 * user of this system and must never become one: giving it a `routeNotification`
 * method would make every future `notify()` call a candidate-facing send by
 * default, which is exactly the mistake to keep impossible.
 *
 * WHY IT SAYS SO MUCH
 * -------------------
 * A candidate is being asked to talk to a camera and be scored on it, usually
 * for a job they want. What will happen, how long it takes, that there are no
 * trick questions, and that a phone will not work — none of that is padding.
 * A candidate who arrives on a phone, or with two minutes to spare, or
 * expecting a form, has been failed before the interview starts, and the
 * product cannot fix any of it once they are there.
 */
final class CandidateInvitationNotification extends Notification
{
    public function __construct(
        private readonly string $entryUrl,
        private readonly string $displayName,
        private readonly string $organizationName,
        private readonly string $projectName,
        private readonly string $expiresAtLabel,
        private readonly CandidateInvitationKind $kind = CandidateInvitationKind::Initial,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        // The retry invitation (scoring-retry-rt-b) differs from the first one in
        // exactly three static lines: subject, introduction and the expiry
        // sentence, which also says the link is single-use. Every other line is
        // shared on purpose, so the requirements and the URL fallback cannot
        // drift between the two. The words never come from the tenant (ruling 10).
        $retry = $this->kind === CandidateInvitationKind::Retry;
        $key = $retry ? 'candidate_invitation.retry.' : 'candidate_invitation.';

        return (new MailMessage)
            ->subject(__($key.'subject', ['project' => $this->projectName]))
            ->greeting(__('candidate_invitation.greeting', ['name' => $this->displayName]))
            ->line(__($key.'intro', [
                'organization' => $this->organizationName,
                'project' => $this->projectName,
            ]))
            ->line(__('candidate_invitation.what_happens'))
            ->line(__('candidate_invitation.before_you_start'))
            // Stated BEFORE the button, not after. The product refuses
            // unsupported browsers and mobile viewports outright (SA-11), so a
            // candidate who reads this afterwards reads it having already been
            // turned away.
            ->line(__('candidate_invitation.requirements'))
            ->action(__('candidate_invitation.action'), $this->entryUrl)
            // Plain text below the button, for the same reason every other
            // message in this product does it: a link that exists only as an
            // anchor is unusable in a client that mangles anchors.
            ->line(__('candidate_invitation.url_fallback'))
            ->line($this->entryUrl)
            ->line(__($key.'expiry', ['date' => $this->expiresAtLabel]))
            ->salutation(__('candidate_invitation.salutation'));
    }
}
