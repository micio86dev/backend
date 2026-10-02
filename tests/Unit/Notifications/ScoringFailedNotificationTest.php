<?php

declare(strict_types=1);

/**
 * ScoringFailedNotification rendering (C12): channel selection and the
 * suppressed-carried line, which appears only when earlier failures were
 * coalesced into this mail.
 */

use App\Notifications\ScoringFailedNotification;

function scoringFailedLines(ScoringFailedNotification $notification): string
{
    $mail = $notification->toMail(null);

    return implode("\n", array_map('strval', [...$mail->introLines, ...$mail->outroLines]));
}

test('it is delivered on the mail channel only', function (): void {
    expect((new ScoringFailedNotification('Acme'))->via(null))->toBe(['mail']);
});

test('the suppressed-carried line is rendered with the count and window when failures were coalesced', function (): void {
    app()->setLocale('en');

    $lines = scoringFailedLines(new ScoringFailedNotification('Acme', 46, 30));

    expect($lines)->toContain(trans_choice('notifications.suppressed_carried', 46, ['count' => 46, 'minutes' => 30]))
        ->and($lines)->toContain('46')
        ->and($lines)->toContain('30');
});

test('the suppressed-carried line sits between the body and the outro', function (): void {
    app()->setLocale('en');

    $mail = (new ScoringFailedNotification('Acme', 2))->toMail(null);
    $all = array_map('strval', [...$mail->introLines, ...$mail->outroLines]);

    expect($all)->toHaveCount(3)
        ->and($all[0])->toBe(__('notifications.scoring_failed.body'))
        ->and($all[1])->toBe(trans_choice('notifications.suppressed_carried', 2, ['count' => 2, 'minutes' => 15]))
        ->and($all[2])->toBe(__('notifications.scoring_failed.outro'));
});

test('no carried line is rendered when nothing was suppressed', function (): void {
    app()->setLocale('en');

    $mail = (new ScoringFailedNotification('Acme'))->toMail(null);

    expect(array_map('strval', [...$mail->introLines, ...$mail->outroLines]))->toBe([
        __('notifications.scoring_failed.body'),
        __('notifications.scoring_failed.outro'),
    ]);
});

test('the footer names the organization', function (): void {
    app()->setLocale('en');

    $mail = (new ScoringFailedNotification('Acme'))->toMail(null);

    expect($mail->salutation)->toBe(__('notifications.footer', ['organization' => 'Acme']))
        ->and((string) $mail->salutation)->toContain('Acme');
});
