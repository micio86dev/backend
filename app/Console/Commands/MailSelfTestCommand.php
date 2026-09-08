<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mail\MailDeliveryProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live round-trip probe against the CONFIGURED mail transport.
 *
 * The gate `openspec/specs/password-recovery/spec.md` sets on a self-service
 * reset flow is literally "until mail is configured and PROVEN to deliver on
 * both services". This command is that proof, and it exists because the
 * failure it checks for is silent by construction:
 *
 *   - `config/mail.php:17` defaults MAIL_MAILER to `log`. Nothing errors, no
 *     exception is thrown, `notification_logs` records `sent` — and the mail
 *     is written to a container filesystem nobody reads. Production has run
 *     this way since C12 shipped, so `ScoringFailedNotification` and
 *     `WebhookDeliveryDeadNotification` have reached nobody.
 *   - A Resend sender on an UNVERIFIED domain also does not fail at boot. It
 *     throws inside the queued job and surfaces as a `failed` row, far from
 *     the config that caused it (api/.env.example:31-33).
 *
 * Both look like a working system from the outside. The only way to tell is
 * to send one real message and say plainly where it went.
 *
 * Deliberately NOT a Pest test: it needs a real transport and real
 * credentials, neither of which belong in `php artisan test --parallel`
 * (phpunit.xml pins `mail.default=array` precisely so the suite never sends).
 * Run it where the credentials live:
 *
 *   local     php artisan beai:mail-selftest --to=you@example.test
 *             then open Mailpit at http://localhost:8025
 *   staging   railway ssh "php artisan beai:mail-selftest --to=you@real.tld"
 *   prod      same, on both the `api` AND `worker` services — they are
 *             separate Railway services with separate variable sets, and the
 *             worker is the one that actually sends operator alerts.
 *
 * Exit codes: 0 delivered through a real transport, 1 refused or failed.
 */
class MailSelfTestCommand extends Command
{
    protected $signature = 'beai:mail-selftest
        {--to= : Recipient address (required). Use a real inbox on staging/prod; anything on local, Mailpit accepts it all}';

    protected $description = 'Send one real message through the configured mailer and report where it actually went';

    protected $help = <<<'HELP'
        Proves the configured mail transport delivers. Prints the mailer, the
        from address and the outcome, then exits non-zero if the message could
        not have reached a human.

        It REFUSES to report success when MAIL_MAILER is `log` or `array`,
        because those accept every message and deliver none — the silent
        failure production has been in since C12.

        On local this goes to Mailpit (http://localhost:8025), which captures
        mail without sending it anywhere real. That makes it safe to run
        repeatedly against any address, including one you do not own.
        HELP;

    public function handle(MailDeliveryProbe $probe): int
    {
        $to = $this->option('to');

        if (! is_string($to) || trim($to) === '') {
            $this->error('--to is required. Nothing was sent.');

            return self::FAILURE;
        }

        $to = trim($to);
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');

        $this->line('');
        $this->info("mailer:  {$mailer}");
        $this->info("from:    {$fromName} <{$from}>");
        $this->info("to:      {$to}");
        $this->line('');

        // Steps 1 and 1b — refuse a transport that cannot deliver, BEFORE the
        // network and before the credentials. A `log` mailer would sail
        // through every step below and print the success this command exists
        // to never print.
        //
        // The detection lives in MailDeliveryProbe now, not here. It was
        // correct in this file and it was never run: production sent nothing
        // for months while this command sat unexecuted. The probe is what the
        // worker's boot gate and the queue health surface also ask, so there
        // is exactly one implementation and no second copy to drift.
        $refusal = $probe->refusal();

        if ($refusal !== null) {
            // The wording is this command's own, rendered from the refusal's
            // STRUCTURED fields rather than reusing its `detail`. Operators
            // read these sentences and the suite pins them; the probe's detail
            // is written for a log line and a boot gate.
            $this->error(match ($refusal->code) {
                'non_delivering_mailer' => "MAIL_MAILER is '{$refusal->mailer}' — this transport delivers NOTHING.",
                'non_delivering_chain' => "MAIL_MAILER is '{$refusal->mailer}' — its transport chain reaches '{$refusal->deadEnd}', which delivers NOTHING.",
                default => "MAIL_MAILER is '{$refusal->mailer}', but it could not be resolved to a transport.",
            });
            $this->line('');

            if ($refusal->code === 'unresolvable_transport') {
                $this->warn('  '.$refusal->detail);
                $this->warn('  Nothing was sent. An unresolvable transport is not a working one.');

                return self::FAILURE;
            }

            $this->warn('  A message sent now is written away and reaches no one, with no error.');
            $this->warn('  Every operator notification on this service is doing that right now.');
            $this->line('');

            if ($refusal->code === 'non_delivering_chain') {
                $this->warn('  The mailer NAME looked fine; the transport underneath it does not.');
                $this->warn('  A composite (failover / roundrobin) delivers nothing the moment it');
                $this->warn('  falls through to a member that delivers nothing.');
                $this->line('');
                $this->line('  Staging / prod:   every member of the chain must be a real transport.');

                return self::FAILURE;
            }

            $this->line('  Local:            MAIL_MAILER=smtp with MAIL_HOST=mailpit (compose pins this already)');
            // Deliberately provider-neutral. This line used to name Resend as
            // the production transport; the deployment it describes runs on
            // SMTP, and an instruction that contradicts the environment is the
            // same class of stale guidance that let this failure survive.
            $this->line('  Staging / prod:   a real transport, its credentials, and a MAIL_FROM_ADDRESS');
            $this->line('                    on a domain VERIFIED with that provider.');
            $this->line('  Set them on THIS service — api and worker are separate Railway services');
            $this->line('  with separate variable sets, and the worker is the one that sends.');

            return self::FAILURE;
        }

        // Step 2 — refuse an obviously unusable sender before spending a send.
        //
        // Resend rejects an unverified sender at the API, but the default
        // `hello@example.com` from config/mail.php:113 never reaches that
        // check on smtp — Mailpit accepts anything, so a local run would pass
        // with a sender that could never work anywhere else.
        if ($from === '' || $from === 'hello@example.com') {
            $this->error("MAIL_FROM_ADDRESS is unset or still the framework default ('{$from}').");
            $this->warn('  Set it to an address on a domain you control and have verified.');

            return self::FAILURE;
        }

        $token = Str::uuid()->toString();
        $sentAt = now()->toIso8601String();

        // Step 3 — send one real message through the real transport.
        //
        // Mail::raw, not a Notification: this probes the TRANSPORT, and a
        // notification would drag in the recipient resolver, the tenant
        // scope and notification_logs — three more things that could fail and
        // muddy what the exit code means.
        try {
            Mail::raw(
                implode("\n", [
                    'BEAI mail self-test.',
                    '',
                    "mailer: {$mailer}",
                    "sent:   {$sentAt}",
                    "token:  {$token}",
                    '',
                    'If you are reading this in a real inbox, the transport works.',
                    'Nothing else is implied: this message went through no queue,',
                    'no notification class and no tenant scope.',
                ]),
                static function ($message) use ($to, $token): void {
                    $message->to($to)->subject("BEAI mail self-test {$token}");
                }
            );
        } catch (Throwable $e) {
            $this->error('Send FAILED: '.$e->getMessage());
            $this->line('');
            $this->warn('  A Resend 4xx here usually means the from-domain is not verified,');
            $this->warn('  or RESEND_API_KEY is missing/wrong on THIS service.');

            return self::FAILURE;
        }

        $this->info("Sent. token {$token}");
        $this->line('');

        if ($mailer === 'smtp') {
            $this->line('  smtp → open Mailpit at http://localhost:8025 and find that token.');
            $this->warn('  Mailpit CAPTURES mail; it does not deliver it. This proves the app can');
            $this->warn('  reach an SMTP server, not that production mail works.');
        } else {
            $this->line("  Check the {$to} inbox for the token above, and the Resend dashboard for the event.");
            $this->warn('  Accepted by the API is not the same as landed in an inbox — confirm both.');
        }

        $this->line('');
        $this->warn('  Run this on the WORKER service too. It is a separate Railway service with');
        $this->warn('  its own variables, and it is the one that sends operator alerts.');

        return self::SUCCESS;
    }
}
