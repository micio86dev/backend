<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A candidate has no evaluation" (C12, D1).
 *
 * A PURE RENDERER — see WebhookDeliveryDeadNotification for why this must never
 * implement ShouldQueue.
 */
final class ScoringFailedNotification extends Notification
{
    public function __construct(
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
            ->subject(__('notifications.scoring_failed.subject'))
            ->greeting(__('notifications.scoring_failed.greeting'))
            ->line(__('notifications.scoring_failed.body'));

        if ($this->suppressedCarriedCount > 0) {
            $message->line(trans_choice('notifications.suppressed_carried', $this->suppressedCarriedCount, [
                'count' => $this->suppressedCarriedCount,
                'minutes' => $this->windowMinutes,
            ]));
        }

        return $message
            ->line(__('notifications.scoring_failed.outro'))
            ->salutation(__('notifications.footer', ['organization' => $this->organizationName]));
    }
}
