<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A webhook delivery has been dead-lettered" (C12, D1).
 *
 * A PURE RENDERER. It MUST NOT implement ShouldQueue — enforced by
 * tests/Arch/Tenancy/NotificationNeverQueuedArchTest.php with no allowlist.
 *
 * The reason is not stylistic. A queued Notification is wrapped by Laravel in
 * Illuminate\Notifications\SendQueuedNotifications, a framework class that
 * executes AFTER Queue::before has reset the ambient tenant resolver to null —
 * and which no architecture scan over app/ can inspect. The queue boundary
 * belongs in SendOperatorNotificationJob, which opens exactly one
 * TenantContextScope::runFor() and then calls Notification::sendNow().
 */
final class WebhookDeliveryDeadNotification extends Notification
{
    /**
     * @param  int  $suppressedCarriedCount  occurrences suppressed behind the
     *                                       storm window since the last send (D4). Zero means nothing was
     *                                       suppressed, and the line is then omitted entirely rather than
     *                                       rendered as "0 further failures".
     */
    public function __construct(
        private readonly int $attempts,
        private readonly string $organizationName,
        private readonly int $suppressedCarriedCount = 0,
        private readonly int $windowMinutes = 15,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('notifications.webhook_delivery_dead.subject'))
            ->greeting(__('notifications.webhook_delivery_dead.greeting'))
            ->line(__('notifications.webhook_delivery_dead.body', ['attempts' => $this->attempts]));

        if ($this->suppressedCarriedCount > 0) {
            $message->line(trans_choice('notifications.suppressed_carried', $this->suppressedCarriedCount, [
                'count' => $this->suppressedCarriedCount,
                'minutes' => $this->windowMinutes,
            ]));
        }

        return $message
            ->line(__('notifications.webhook_delivery_dead.outro'))
            ->salutation(__('notifications.footer', ['organization' => $this->organizationName]));
    }
}
