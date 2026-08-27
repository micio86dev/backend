<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password-reset email (self-service-password-reset AD-6, AD-8).
 *
 * A PURE RENDERER. It MUST NOT implement `ShouldQueue` — `tests/Arch/Tenancy/
 * NotificationNeverQueuedArchTest.php` forbids it for every class under
 * `app/Notifications/`, with no allowlist, and the rule is keyed on the SHAPE
 * rather than on any string the file happens to contain. The queue boundary
 * for this flow is `SendPasswordResetLinkJob`, which AD-3 needs anyway to keep
 * the request timing-flat.
 *
 * NOT a C12 `notifications` trigger (AD-8). That capability's first requirement
 * is literally "Trigger Set Is Exactly Two Events", and a self-service reset is
 * neither rare nor operator-facing — it is an action by the recipient
 * themselves. It writes no `notification_logs` row and never touches
 * `SendOperatorNotificationJob`'s org-scoped recipient resolution, which could
 * not express "one specific user, possibly a platform superadmin with
 * organization_id IS NULL" in any case.
 *
 * THE FULL URL APPEARS TWICE, ON PURPOSE
 * --------------------------------------
 * Once as the button's target, once as plain text below it. A link that exists
 * only as an anchor href is unusable in a client that strips or mangles
 * anchors, and unverifiable by a reader who wants to see where a
 * security-sensitive link goes before clicking it. This is the one message a
 * locked-out user must be able to act on.
 *
 * The URL is built by the caller from `services.backoffice_origin`, never
 * guessed here — see `SendPasswordResetLinkJob`.
 */
final class ResetPasswordNotification extends Notification
{
    public function __construct(
        private readonly string $resetUrl,
        private readonly int $expiresInMinutes,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('password_reset.subject'))
            ->greeting(__('password_reset.greeting'))
            ->line(__('password_reset.line_1'))
            ->action(__('password_reset.action'), $this->resetUrl)
            // Plain text below the button — see the class docblock.
            ->line(__('password_reset.url_fallback'))
            ->line($this->resetUrl)
            ->line(__('password_reset.expiry', ['minutes' => $this->expiresInMinutes]))
            // Reassurance, no IP and no user agent (AD-6 / proposal question 6):
            // an IP in an email is weak security theatre and a small privacy
            // leak, while the reassurance line measurably reduces support
            // contacts.
            ->line(__('password_reset.not_you'))
            ->salutation(__('password_reset.salutation'));
    }
}
