<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationSubjectType;
use App\Enums\NotificationType;
use App\Events\WebhookDeliveryDead;
use App\Jobs\SendOperatorNotificationJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auto-discovered, PLAIN (not queued) listener (C12, D8 layer 4).
 *
 * Plain on purpose: the work is one dispatch, and a queued listener would add a
 * second queue hop for no benefit while putting the tenant-context problem in a
 * framework wrapper this codebase cannot inspect.
 *
 * The whole body is wrapped in try/catch(Throwable). A notification bug must
 * never propagate into the webhook delivery path that emitted the event — the
 * alert is strictly less important than the thing it is reporting on.
 */
final class NotifyOnWebhookDeliveryDead
{
    public function handle(WebhookDeliveryDead $event): void
    {
        try {
            // ->afterCommit() so Queue::before fires with a committed row
            // behind it — the job re-loads the subject and would otherwise be
            // able to race the transaction that wrote it.
            SendOperatorNotificationJob::dispatch(
                NotificationType::WebhookDeliveryDead,
                NotificationSubjectType::WebhookDelivery,
                $event->deliveryId,
            )->afterCommit();
        } catch (Throwable $e) {
            Log::error('notification.listener.failed', [
                'listener' => self::class,
                'delivery_id' => $event->deliveryId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
