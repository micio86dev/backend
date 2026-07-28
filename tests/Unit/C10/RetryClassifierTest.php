<?php

declare(strict_types=1);

/**
 * RED — 10.1: RetryClassifier (C10, design.md D2/D3/D5).
 *
 * Table-driven per the design's own bucket boundaries:
 *   2xx                              → Delivered
 *   408 (Request Timeout)            → Retryable
 *   429 (Too Many Requests)          → Retryable
 *   5xx                              → Retryable
 *   timeout / connection error       → Retryable (no HTTP status at all)
 *   any OTHER 4xx                    → FailedPermanent (will not fix itself on retry)
 *
 * Boundary cases exercised explicitly (408 vs 407, 429 vs 430, 499 vs 500, 200 vs 199,
 * 299 vs 300) because a fencepost error here silently changes which failures get
 * retried forever vs. never — a security/reliability-relevant classification.
 */

use App\Enums\WebhookDeliveryOutcome;
use App\Services\Webhooks\RetryClassifier;

test('2xx statuses classify as Delivered', function (int $status): void {
    expect((new RetryClassifier)->classify($status))->toBe(WebhookDeliveryOutcome::Delivered);
})->with([200, 201, 204, 299]);

test('199 and 300 are NOT classified as Delivered (2xx boundary)', function (int $status): void {
    expect((new RetryClassifier)->classify($status))->not->toBe(WebhookDeliveryOutcome::Delivered);
})->with([199, 300]);

test('408 and 429 classify as Retryable (explicit named exceptions to the 4xx=permanent rule)', function (int $status): void {
    expect((new RetryClassifier)->classify($status))->toBe(WebhookDeliveryOutcome::Retryable);
})->with([408, 429]);

test('407 and 430 do NOT get the 408/429 exception (boundary check)', function (int $status): void {
    expect((new RetryClassifier)->classify($status))->toBe(WebhookDeliveryOutcome::FailedPermanent);
})->with([407, 430]);

test('5xx statuses classify as Retryable', function (int $status): void {
    expect((new RetryClassifier)->classify($status))->toBe(WebhookDeliveryOutcome::Retryable);
})->with([500, 502, 503, 599]);

test('499 is NOT retryable (5xx lower boundary) and 600 is out of any known bucket, defensively Retryable', function (): void {
    expect((new RetryClassifier)->classify(499))->toBe(WebhookDeliveryOutcome::FailedPermanent);
    // Anything >= 500 is retryable by design, including unusual/non-standard codes —
    // defensively safer than silently treating an unrecognized 5xx as permanent.
    expect((new RetryClassifier)->classify(600))->toBe(WebhookDeliveryOutcome::Retryable);
});

test('any other 4xx classifies as FailedPermanent — a 400/404 will not fix itself on retry', function (int $status): void {
    expect((new RetryClassifier)->classify($status))->toBe(WebhookDeliveryOutcome::FailedPermanent);
})->with([400, 401, 403, 404, 409, 422]);

test('a timeout or connection error (no HTTP status at all) classifies as Retryable', function (): void {
    expect((new RetryClassifier)->classify(null))->toBe(WebhookDeliveryOutcome::Retryable);
});
