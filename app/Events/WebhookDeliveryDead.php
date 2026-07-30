<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A webhook delivery has exhausted its attempts and been dead-lettered
 * (C12, D5 / webhooks-integration delta spec).
 *
 * Carries a REFERENCE only — the delivery's primary key, nothing else.
 *
 * That is not minimalism for its own sake. A consumer must re-derive the
 * organization from a fresh database load, because an org id carried on an
 * event is a value someone can trust without having verified, and this event
 * crosses a queue boundary where `Queue::before` has already reset the ambient
 * tenant resolver to null. A reference forces the consumer to establish tenancy
 * from the row it actually loaded.
 *
 * Emitted at BOTH dead-lettering sites in DeliverWebhookJob — recordRetryable()
 * (the modelled path) and failed() (the safety net). They are mutually
 * exclusive per row, and the notification dedupe row makes double emission
 * harmless regardless; emitting from only one would be a silent hole.
 */
final class WebhookDeliveryDead
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly int $deliveryId) {}
}
