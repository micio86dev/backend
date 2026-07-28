<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryOutcome;

/**
 * RetryClassifier — maps a single delivery attempt's HTTP status (or its absence, on
 * a timeout/connection error) to an immediate outcome (C10, design.md D2/D5).
 *
 * Buckets:
 *   2xx                        → Delivered
 *   408 (Request Timeout)      → Retryable  (explicit exception to the 4xx rule)
 *   429 (Too Many Requests)    → Retryable  (explicit exception to the 4xx rule)
 *   5xx (including non-standard codes >= 500) → Retryable
 *   null (timeout/connection error, no response at all) → Retryable
 *   any OTHER 4xx              → FailedPermanent — a 400/404 will not fix itself by
 *                                 retrying the exact same request unchanged.
 *
 * REQ: RetryClassifier (C10 D2/D5)
 */
final class RetryClassifier
{
    public function classify(?int $statusCode): WebhookDeliveryOutcome
    {
        if ($statusCode === null) {
            return WebhookDeliveryOutcome::Retryable;
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return WebhookDeliveryOutcome::Delivered;
        }

        if (in_array($statusCode, [408, 429], true)) {
            return WebhookDeliveryOutcome::Retryable;
        }

        if ($statusCode >= 500) {
            return WebhookDeliveryOutcome::Retryable;
        }

        // The preceding `>= 500` branch already excludes 500+, so this is exactly
        // the remaining 4xx range (400-407, 409-428, 430-499 after the 408/429
        // branch above).
        if ($statusCode >= 400) {
            return WebhookDeliveryOutcome::FailedPermanent;
        }

        // 1xx / 3xx and anything else outside the modeled ranges: defensively
        // retryable rather than silently discarding an unexpected response class.
        return WebhookDeliveryOutcome::Retryable;
    }
}
