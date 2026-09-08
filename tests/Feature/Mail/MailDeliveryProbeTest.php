<?php

declare(strict_types=1);

/**
 * RED — MailDeliveryProbe (mail-delivery-guard, 2026-09-08).
 *
 * The probe answers one question: can the mail transport this process has
 * actually configured deliver a message to a human?
 *
 * It exists because the answer was "no" in production for months and nothing
 * said so. `config/mail.php:17` defaults `MAIL_MAILER` to `log`; the Railway
 * `worker` service — the ONLY process that sends, since every notification is
 * queued — had no `MAIL_MAILER` at all. Every invitation, every password
 * reset and every operator alert was written to a log file and reported as
 * sent. `SendCandidateInvitationJob ... DONE` in 328ms, day after day.
 *
 * `MailSelfTestCommand` already knew how to detect this. It was never run.
 * That is the whole argument for extracting the logic to somewhere that runs
 * BY ITSELF.
 */

use App\Support\Mail\MailDeliveryProbe;
use Illuminate\Support\Facades\Mail;

function forgetResolvedMailers(): void
{
    // MailManager caches each resolved mailer, so flipping `mail.default`
    // without this returns the mailer built from the PREVIOUS config and
    // every assertion below silently tests the same transport twice.
    Mail::forgetMailers();
}

test('a log mailer is refused — it accepts everything and delivers nothing', function (): void {
    config(['mail.default' => 'log']);
    forgetResolvedMailers();

    $refusal = app(MailDeliveryProbe::class)->refusal();

    expect($refusal)->not->toBeNull();
    expect($refusal->code)->toBe('non_delivering_mailer');
    expect($refusal->detail)->toContain('log');
});

test('an array mailer is refused for the same reason', function (): void {
    config(['mail.default' => 'array']);
    forgetResolvedMailers();

    expect(app(MailDeliveryProbe::class)->refusal()?->code)->toBe('non_delivering_mailer');
});

test('a real transport passes', function (): void {
    // Resolving an smtp transport opens no socket, so this asserts the
    // configuration without needing a mail server.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit']);
    forgetResolvedMailers();

    expect(app(MailDeliveryProbe::class)->refusal())->toBeNull();
});

test('a failover chain that falls through to log is refused, though its NAME looks fine', function (): void {
    // `failover` is a stock mailer whose default members are ['smtp', 'log'].
    // The name is not on any deny list and never will be — the chain has to
    // be followed, because a composite delivers nothing the moment it falls
    // through to a member that delivers nothing.
    config([
        'mail.default' => 'failover',
        'mail.mailers.failover.mailers' => ['smtp', 'log'],
    ]);
    forgetResolvedMailers();

    $refusal = app(MailDeliveryProbe::class)->refusal();

    expect($refusal?->code)->toBe('non_delivering_chain');
    expect($refusal?->detail)->toContain('log');
});

test('a mailer that cannot be resolved at all is refused, not thrown', function (): void {
    // A typo'd MAIL_MAILER, or a driver that is not installed. "Cannot tell"
    // is a no: a gate that escapes as a stack trace is a gate that stops the
    // process it was meant to protect for the wrong reason.
    config(['mail.default' => 'not-a-real-mailer']);
    forgetResolvedMailers();

    expect(app(MailDeliveryProbe::class)->refusal()?->code)->toBe('unresolvable_transport');
});

test('the resolved mailer NAME is reported whether or not it delivers', function (): void {
    // The health surface renders this, so it must be readable even in the
    // states the probe refuses.
    config(['mail.default' => 'log']);
    forgetResolvedMailers();
    expect(app(MailDeliveryProbe::class)->mailerName())->toBe('log');

    config(['mail.default' => 'smtp']);
    forgetResolvedMailers();
    expect(app(MailDeliveryProbe::class)->mailerName())->toBe('smtp');
});

test('delivers() is the inverse of a refusal, so no caller re-derives it', function (): void {
    config(['mail.default' => 'log']);
    forgetResolvedMailers();
    expect(app(MailDeliveryProbe::class)->delivers())->toBeFalse();

    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit']);
    forgetResolvedMailers();
    expect(app(MailDeliveryProbe::class)->delivers())->toBeTrue();
});
