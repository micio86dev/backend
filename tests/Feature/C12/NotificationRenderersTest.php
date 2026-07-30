<?php

declare(strict_types=1);

/**
 * The notification renderers (C12, D1/D7).
 *
 * These assert the two things that actually break silently: the locale reaching
 * the rendered body, and the carried count being omitted rather than rendered
 * as "0 further failures".
 */

use App\Notifications\ScoringFailedNotification;
use App\Notifications\WebhookDeliveryDeadNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

test('neither renderer implements ShouldQueue', function (): void {
    // The arch guard covers app/Notifications wholesale; this pins the two
    // classes that exist today by name, so a rename cannot quietly drop them
    // out of the scan.
    expect(is_subclass_of(WebhookDeliveryDeadNotification::class, ShouldQueue::class))->toBeFalse();
    expect(is_subclass_of(ScoringFailedNotification::class, ShouldQueue::class))->toBeFalse();
});

test('the body renders in Italian when the notification locale is it', function (): void {
    $mail = (new WebhookDeliveryDeadNotification(6, 'Acme'))
        ->locale('it')
        ->toMail(null);

    // ->locale() only takes effect through the framework's send path, so assert
    // the translated string directly under a switched locale instead of
    // pretending the fluent call did it.
    app()->setLocale('it');
    $mail = (new WebhookDeliveryDeadNotification(6, 'Acme'))->toMail(null);

    expect($mail->subject)->toBe(__('notifications.webhook_delivery_dead.subject', [], 'it'));
    expect($mail->subject)->not->toBe(__('notifications.webhook_delivery_dead.subject', [], 'en'));
});

test('an unsupported locale falls back rather than rendering a translation key', function (): void {
    app()->setLocale('zz');

    $mail = (new ScoringFailedNotification('Acme'))->toMail(null);

    // The failure mode this prevents is an email whose subject line literally
    // reads "notifications.scoring_failed.subject".
    expect($mail->subject)->not->toContain('notifications.');
});

test('the carried count line is omitted entirely when nothing was suppressed', function (): void {
    app()->setLocale('en');

    $withNone = (new WebhookDeliveryDeadNotification(6, 'Acme', 0))->toMail(null);
    $withSome = (new WebhookDeliveryDeadNotification(6, 'Acme', 46))->toMail(null);

    $render = fn ($mail): string => implode(' ', array_map('strval', $mail->introLines + $mail->outroLines));

    expect($render($withNone))->not->toContain('suppressed');
    expect($render($withSome))->toContain('46');
});

test('the carried count is pluralised, not concatenated', function (): void {
    app()->setLocale('en');

    $one = trans_choice('notifications.suppressed_carried', 1, ['count' => 1, 'minutes' => 15]);
    $many = trans_choice('notifications.suppressed_carried', 46, ['count' => 46, 'minutes' => 15]);

    expect($one)->toContain('failure was');
    expect($many)->toContain('failures were');
});

test('Italian copy exists for every English key', function (): void {
    $en = require lang_path('en/notifications.php');
    $it = require lang_path('it/notifications.php');

    // i18n it/en is mandatory. A missing Italian key does not error — it
    // silently renders the English string, which reads as a bug to the reader
    // and as "done" to the developer.
    $flatten = function (array $a, string $prefix = '') use (&$flatten): array {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            $out = array_merge($out, is_array($v) ? $flatten($v, $key) : [$key]);
        }

        return $out;
    };

    expect($flatten($it))->toBe($flatten($en));
});
