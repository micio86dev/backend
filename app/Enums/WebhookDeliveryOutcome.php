<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The immediate classification of a single delivery attempt's outcome
 * (C10 — Webhooks Integration, design.md D2/D5).
 *
 * Distinct from WebhookDeliveryStatus: this is RetryClassifier's pure output for ONE
 * HTTP attempt, before DeliverWebhookJob decides whether a Retryable outcome becomes
 * another 'pending' cycle or exhausts to 'dead' (that decision needs attempt_count vs
 * max_attempts, which the classifier does not know about).
 *
 * REQ: RetryClassifier (C10 D2/D5)
 */
enum WebhookDeliveryOutcome: string
{
    case Delivered = 'delivered';
    case Retryable = 'retryable';
    case FailedPermanent = 'failed_permanent';
}
