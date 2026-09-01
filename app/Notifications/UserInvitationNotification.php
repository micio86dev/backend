<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation a newly created backoffice user receives.
 *
 * A PURE RENDERER. It MUST NOT implement `ShouldQueue` — `tests/Arch/Tenancy/
 * NotificationNeverQueuedArchTest.php` forbids it for every class under
 * `app/Notifications/`, keyed on the SHAPE rather than on any string the file
 * contains. The queue boundary is `SendUserInvitationJob`, exactly as
 * `SendPasswordResetLinkJob` is for the reset flow.
 *
 * IT CARRIES A PASSWORD-SET LINK, NEVER A PASSWORD
 * -------------------------------------------------
 * `POST /api/users` takes a password from the admin creating the account, so
 * the obvious invitation would repeat it back over email. It does not. A
 * password in an inbox is a password in every backup, forward and search index
 * that inbox is part of, for as long as the mailbox exists — and the admin who
 * chose it knows it, which is one person too many. The link mints the same
 * broker token the reset flow uses, so the recipient sets a secret only they
 * have ever seen.
 *
 * THE ROLE PARAGRAPH IS PICKED, NOT SUBSTITUTED
 * ----------------------------------------------
 * "You have been given the operator role" tells someone nothing they can act
 * on. Each role gets a paragraph about what THEY can do, because those differ
 * enough that one sentence with a substituted role name would be vague for all
 * three. An unknown role falls back to the most restrictive text: describing
 * more power than someone has is the failure that matters.
 */
final class UserInvitationNotification extends Notification
{
    public function __construct(
        private readonly string $setPasswordUrl,
        private readonly int $expiresInMinutes,
        private readonly string $role,
        private readonly string $inviterName,
        private readonly string $organizationName,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('user_invitation.subject'))
            ->greeting(__('user_invitation.greeting'))
            ->line(__('user_invitation.intro', [
                'inviter' => $this->inviterName,
                'organization' => $this->organizationName,
            ]))
            // What the product IS, before what their role is: somebody who has
            // never heard of BEAI cannot make sense of "you can review
            // evaluations".
            ->line(__('user_invitation.what_it_is'))
            ->line(__('user_invitation.your_role_heading'))
            ->line($this->roleLine())
            ->line(__('user_invitation.how_to_start'))
            ->action(__('user_invitation.action'), $this->setPasswordUrl)
            // Plain text below the button, for the same reason the reset mail
            // does it: a link that exists only as an anchor is unusable in a
            // client that mangles anchors, and unverifiable by a reader who
            // wants to see where it goes before clicking.
            ->line(__('user_invitation.url_fallback'))
            ->line($this->setPasswordUrl)
            ->line(__('user_invitation.expiry', ['minutes' => $this->expiresInMinutes]))
            ->salutation(__('user_invitation.salutation'));
    }

    /**
     * Falls back to the OBSERVER text for anything unrecognised.
     *
     * Deliberately the most restrictive of the three. An invitation that
     * promises an operator's abilities to someone who has a viewer's is a
     * message that sends them looking for buttons that are not there; the
     * reverse merely understates, and the product itself corrects it on their
     * first visit.
     */
    private function roleLine(): string
    {
        return match ($this->role) {
            'admin' => __('user_invitation.role_admin'),
            'operator' => __('user_invitation.role_operator'),
            default => __('user_invitation.role_viewer'),
        };
    }
}
