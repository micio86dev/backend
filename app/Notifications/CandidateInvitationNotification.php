<?php

declare(strict_types=1);

namespace App\Notifications;

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
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('candidate_invitation.subject', ['project' => $this->projectName]))
            ->greeting(__('candidate_invitation.greeting', ['name' => $this->displayName]))
            ->line(__('candidate_invitation.intro', [
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
            ->line(__('candidate_invitation.expiry', ['date' => $this->expiresAtLabel]))
            ->salutation(__('candidate_invitation.salutation'));
    }
}
