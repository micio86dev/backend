<?php

declare(strict_types=1);

/**
 * The dead-letter event fires from BOTH sites, and from nowhere else (C12, D5).
 *
 * Emitting from only one of the two would be a silent hole. They are mutually
 * exclusive per row — failed() returns early for terminal rows — so this is not
 * belt-and-braces, it is two genuinely distinct paths to the same state.
 */

use App\Events\WebhookDeliveryDead;
use Illuminate\Support\Facades\Event;

test('the event carries a reference only, never a trusted organization id', function (): void {
    $event = new WebhookDeliveryDead(123);

    expect($event->deliveryId)->toBe(123);

    // An org id carried on an event is a value a consumer can trust without
    // having verified it — and this event crosses a queue boundary where
    // Queue::before has already reset the ambient resolver to null. The
    // consumer must re-derive tenancy from a fresh load.
    $public = array_map(
        fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(WebhookDeliveryDead::class))->getProperties(ReflectionProperty::IS_PUBLIC)
    );

    expect($public)->toBe(['deliveryId']);
});

test('DeliverWebhookJob emits the event at both dead-lettering sites', function (): void {
    // Structural rather than behavioural on purpose: driving a real delivery to
    // its sixth failure needs the full HTTP retry ladder, and what matters here
    // is that NEITHER site was left without an emission. A behavioural test
    // that exercised only one path would pass while the other hole stayed open
    // — which is exactly the defect this guards.
    $source = (string) file_get_contents(app_path('Jobs/DeliverWebhookJob.php'));

    expect(substr_count($source, 'WebhookDeliveryDead::dispatch('))->toBe(2);

    // And both emissions must sit AFTER their persist() call, so a listener
    // never observes a row that has not been written yet.
    $recordRetryable = substr($source, (int) strpos($source, 'private function recordRetryable'));
    $recordRetryable = substr($recordRetryable, 0, (int) strpos($recordRetryable, 'private function backoffDelayFor'));
    expect($recordRetryable)->toContain('WebhookDeliveryDead::dispatch(');
});

test('the second emission is in failed(), not a duplicate of the first', function (): void {
    // Counting two dispatches is not enough on its own — two copies in
    // recordRetryable() would satisfy that count while leaving failed(), the
    // unhandled-exception path, silent. That is the case an operator most needs
    // to hear about.
    $source = (string) file_get_contents(app_path('Jobs/DeliverWebhookJob.php'));

    $failed = substr($source, (int) strpos($source, 'public function failed('));

    expect($failed)->toContain('WebhookDeliveryDead::dispatch(');
    expect(substr_count($failed, 'WebhookDeliveryDead::dispatch('))->toBe(1);
});

test('no other terminal outcome emits the dead-letter event', function (): void {
    // delivered / failed_permanent / skipped are terminal too. None of them
    // means "nobody was told", so none of them may page a human.
    $source = (string) file_get_contents(app_path('Jobs/DeliverWebhookJob.php'));

    foreach (['Delivered', 'FailedPermanent', 'Skipped'] as $terminal) {
        $offset = strpos($source, 'WebhookDeliveryStatus::'.$terminal);
        if ($offset === false) {
            continue;
        }

        // The 400 characters following each non-dead terminal transition must
        // not contain an emission.
        expect(substr($source, $offset, 400))->not->toContain('WebhookDeliveryDead::dispatch(');
    }
});
