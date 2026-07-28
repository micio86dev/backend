<?php

declare(strict_types=1);

/**
 * RED — 2.3: WebhookEventType / WebhookDeliveryStatus / WebhookSkipReason enums (C10).
 *
 * Primary assertion (Δ1 reconciliation): WebhookSkipReason has exactly 3 cases after
 * the spec/design delivery-gate reconciliation (null_url → secret_null →
 * event_type_disabled → pending). The other two enums are covered for completeness.
 */

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Enums\WebhookSkipReason;

test('WebhookSkipReason has exactly 3 cases post-Δ1', function (): void {
    expect(WebhookSkipReason::cases())->toHaveCount(3);
});

test('WebhookSkipReason cases match the Δ1 4-step delivery gate order', function (): void {
    $values = array_map(fn (WebhookSkipReason $case): string => $case->value, WebhookSkipReason::cases());

    expect($values)->toBe(['no_webhook_url', 'no_webhook_secret', 'event_type_disabled']);
});

test('WebhookEventType has exactly the closed progress/evaluation set', function (): void {
    $values = array_map(fn (WebhookEventType $case): string => $case->value, WebhookEventType::cases());

    expect($values)->toEqualCanonicalizing(['progress', 'evaluation']);
});

test('WebhookDeliveryStatus has exactly the 5 documented states', function (): void {
    $values = array_map(fn (WebhookDeliveryStatus $case): string => $case->value, WebhookDeliveryStatus::cases());

    expect($values)->toEqualCanonicalizing([
        'pending', 'delivered', 'failed_permanent', 'dead', 'skipped',
    ]);
});
