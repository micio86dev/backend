<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use ReflectionProperty;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
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
    /**
     * Transports that accept a message and deliver it nowhere. Reporting
     * success on these is the exact lie this command exists to prevent.
     */
    private const NON_DELIVERING = ['log', 'array'];

    /**
     * Transport CLASSES that accept a message and deliver it nowhere.
     *
     * The name list above is not enough on its own, and the gap is not
     * hypothetical: `failover` is a stock mailer in `config/mail.php:82-89`
     * whose default members are `['smtp', 'log']`. Symfony's failover
     * transport falls through to the next member the moment one fails, so a
     * production `MAIL_MAILER=failover` with a broken SMTP host lands every
     * message in `log` — and `failover` is not a name on the list, so this
     * command printed `Sent.` and exited 0 on it. That is precisely the lie
     * the command exists to prevent, reachable without editing any config.
     *
     * So the gate follows the transport that actually RESOLVES, whatever the
     * mailer is called. `failover` and `roundrobin` are deliberately NOT
     * added to the name list: both can genuinely deliver, and refusing them
     * outright would trade a false pass for a false fail. A gate that cries
     * wolf gets bypassed, which leaves you worse off than the hole it closed.
     */
    private const NON_DELIVERING_TRANSPORTS = [
        ArrayTransport::class,
        LogTransport::class,
        NullTransport::class,
    ];

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

    public function handle(): int
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

        // Step 1 — refuse a transport that cannot deliver.
        //
        // Before the network, before the credentials: a `log` mailer would
        // sail through every step below and print a success this command
        // exists to never print.
        if (in_array($mailer, self::NON_DELIVERING, true)) {
            $this->error("MAIL_MAILER is '{$mailer}' — this transport delivers NOTHING.");
            $this->line('');
            $this->warn('  A message sent now is written away and reaches no one, with no error.');
            $this->warn('  Every operator notification on this service is doing that right now.');
            $this->line('');
            $this->line('  Local:            MAIL_MAILER=smtp with MAIL_HOST=mailpit (compose pins this already)');
            $this->line('  Staging / prod:   MAIL_MAILER=resend, RESEND_API_KEY, and a MAIL_FROM_ADDRESS');
            $this->line('                    on a domain VERIFIED in the Resend dashboard.');

            return self::FAILURE;
        }

        // Step 1b — refuse the transport that actually RESOLVES.
        //
        // Step 1 judges the label; this judges the thing. They are not the
        // same question, and only one of them survives `MAIL_MAILER=failover`
        // (see NON_DELIVERING_TRANSPORTS). It runs AFTER the name check so
        // the overwhelmingly common `MAIL_MAILER=log` still gets its specific
        // message without depending on a transport instantiation that can
        // itself throw, and BEFORE the `from` check so the failure that is
        // silent in production is always the one named first.
        try {
            $transport = Mail::getSymfonyTransport();
        } catch (Throwable $e) {
            // A typo'd MAIL_MAILER, or a driver that is not installed. Before
            // this, the exception escaped as a stack trace. A gate must be
            // able to say no legibly, and "cannot tell" is a no.
            $this->error("MAIL_MAILER is '{$mailer}', but it could not be resolved to a transport.");
            $this->line('');
            $this->warn('  '.$e->getMessage());
            $this->warn('  Nothing was sent. An unresolvable transport is not a working one.');

            return self::FAILURE;
        }

        $deadEnd = $this->firstNonDeliveringTransport($transport);

        if ($deadEnd !== null) {
            $this->error("MAIL_MAILER is '{$mailer}' — its transport chain reaches '{$deadEnd}', which delivers NOTHING.");
            $this->line('');
            $this->warn('  The mailer NAME looked fine; the transport underneath it does not.');
            $this->warn('  A composite (failover / roundrobin) delivers nothing the moment it');
            $this->warn('  falls through to a member that delivers nothing.');
            $this->line('');
            $this->line("  Full chain:       {$transport}");
            $this->line('  Staging / prod:   every member of the chain must be a real transport.');

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

    /**
     * Walk a resolved transport tree and return the name of the first member
     * that delivers nothing, or null if every leaf can actually deliver.
     *
     * Returns a NAME rather than a bool on purpose. "Refused" tells an
     * operator to go looking; "refused: the chain reaches 'log'" tells them
     * what to change.
     */
    private function firstNonDeliveringTransport(TransportInterface $transport): ?string
    {
        // FailoverTransport EXTENDS RoundRobinTransport, so this one branch
        // covers both stock composites — and any future one built on them.
        if ($transport instanceof RoundRobinTransport) {
            foreach ($this->membersOf($transport) as $member) {
                $found = $this->firstNonDeliveringTransport($member);

                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        foreach (self::NON_DELIVERING_TRANSPORTS as $class) {
            if ($transport instanceof $class) {
                // Every one of these stringifies to its driver name
                // ('log', 'array', 'null://').
                return (string) $transport;
            }
        }

        return null;
    }

    /**
     * The members of a composite transport.
     *
     * Read by reflection because Symfony declares `$transports` as a PRIVATE
     * promoted constructor property with no accessor
     * (symfony/mailer/Transport/RoundRobinTransport.php:39). The alternative —
     * re-walking `config('mail.mailers.*.mailers')` — would judge the config
     * rather than the object, which is the mistake being corrected here: a
     * composite registered through `Mail::extend()` has no such config to
     * read.
     *
     * The ReflectionProperty is taken from the DECLARING class, not from
     * `$transport::class`. A private property belongs to the class that
     * declares it, so asking a FailoverTransport instance for its own
     * `transports` property would not find it.
     *
     * @return list<TransportInterface>
     */
    private function membersOf(RoundRobinTransport $transport): array
    {
        $members = (new ReflectionProperty(RoundRobinTransport::class, 'transports'))->getValue($transport);

        if (! is_array($members)) {
            return [];
        }

        return array_values(array_filter(
            $members,
            static fn (mixed $member): bool => $member instanceof TransportInterface,
        ));
    }
}
