<?php

declare(strict_types=1);

/**
 * RED — SendScheduledInterviewNoticeJob + CandidateInterviewNoticeNotification
 * (interview-scheduling PR-D, design AD-9, tasks T-D1/T-D2).
 *
 * The advance notice a scheduled candidate receives BEFORE their interview
 * link arrives — deliberately carrying no link and no expiry, unlike
 * CandidateInvitationNotification, which this class is not a copy of.
 */

use App\Jobs\SendScheduledInterviewNoticeJob;
use App\Notifications\CandidateInterviewNoticeNotification;
use Illuminate\Support\Facades\Notification;

test('the notice job sends the notice notification, addressed to the candidate and localized', function (): void {
    Notification::fake();

    (new SendScheduledInterviewNoticeJob(
        'giulia@example.test',
        'Giulia Ferrari',
        'Acme Assessments',
        'Sales Team 2026',
        'it',
    ))->handle();

    Notification::assertSentOnDemand(
        CandidateInterviewNoticeNotification::class,
        function ($notification, $channels, $notifiable): bool {
            expect($notification->locale)->toBe('it')
                ->and($notifiable->routes['mail'])->toBe('giulia@example.test');

            return true;
        }
    );
});

test('the notice carries NO link and NO expiry, unlike the start invitation', function (): void {
    Notification::fake();

    (new SendScheduledInterviewNoticeJob(
        'giulia@example.test',
        'Giulia Ferrari',
        'Acme Assessments',
        'Sales Team 2026',
        'en',
    ))->handle();

    Notification::assertSentOnDemand(
        CandidateInterviewNoticeNotification::class,
        function ($notification, $channels, $notifiable): bool {
            $mail = $notification->toMail($notifiable);
            $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

            expect($mail->actionUrl)->toBeNull()
                ->and($body)->not->toContain('http://')
                ->and($body)->not->toContain('https://')
                ->and($body)->toContain('Acme Assessments')
                ->and($body)->toContain('Sales Team 2026');

            return true;
        }
    );
});

test('it renders in both it and en without falling back to missing-key placeholders', function (string $locale, string $expectedFragment): void {
    Notification::fake();
    // Notification::locale() only switches locale around the REAL send
    // pipeline (the notification channel manager) — a test that calls
    // toMail() directly, as assertSentOnDemand's callback does below, bypasses
    // that wrapper entirely and renders under the CURRENT app locale. Setting
    // it explicitly is what actually exercises each locale's translation
    // file, not the job's own stored $locale property (already covered by
    // the "addressed to the candidate and localized" test above).
    app()->setLocale($locale);

    (new SendScheduledInterviewNoticeJob(
        'giulia@example.test',
        'Giulia Ferrari',
        'Acme Assessments',
        'Sales Team 2026',
        $locale,
    ))->handle();

    Notification::assertSentOnDemand(
        CandidateInterviewNoticeNotification::class,
        function ($notification, $channels, $notifiable) use ($expectedFragment): bool {
            $mail = $notification->toMail($notifiable);

            expect($mail->subject)->toContain($expectedFragment);

            return true;
        }
    );
})->with([
    'it' => ['it', 'in arrivo'],
    'en' => ['en', 'coming up'],
]);
