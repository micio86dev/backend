<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Throwable;

/**
 * Can THIS process actually deliver mail? (mail-delivery-guard, 2026-09-08)
 *
 * The logic here was already written, inside `MailSelfTestCommand`, and it
 * was correct. It was also never run. Production sent nothing for months:
 * `config/mail.php:17` defaults `MAIL_MAILER` to `log`, the Railway `worker`
 * service had no `MAIL_MAILER` at all, and since every notification in this
 * system is queued, the worker is the only process that sends. Invitations,
 * password resets and operator alerts were written to a log file and
 * reported as delivered — `SendCandidateInvitationJob ... DONE` in 328ms,
 * every time.
 *
 * So the detection moved to a class two things call BY THEMSELVES: the
 * worker refuses to boot on it in production, and the queue health surface
 * reports it on every response. `MailSelfTestCommand` now asks this class
 * rather than carrying a second copy — an earlier version of exactly this
 * mistake is documented in `QueueRuntimeInvariant`, where a duplicated
 * implementation meant two copies could drift while both looked green.
 *
 * It resolves a transport. It never sends: sending is the self-test's job,
 * and a boot gate that emails somebody on every container start would be a
 * worse problem than the one it closes.
 */
final class MailDeliveryProbe
{
    /**
     * Mailer NAMES that accept every message and deliver none.
     *
     * Legitimate locally and in tests — `phpunit.xml` pins `array` precisely
     * so the suite never sends. It is production where they are a silent
     * outage, which is why the callers, not this class, decide what to do
     * about a refusal.
     */
    private const NON_DELIVERING_NAMES = ['log', 'array'];

    /**
     * Transport CLASSES that deliver nothing.
     *
     * The name list is not enough, and the gap is not hypothetical:
     * `failover` is a stock mailer whose default members are
     * `['smtp', 'log']`. Symfony falls through to the next member the moment
     * one fails, so a production `failover` with a broken SMTP host lands
     * every message in `log` — and `failover` is not a name on any list.
     *
     * `failover` and `roundrobin` are deliberately NOT added to the name
     * list: both can genuinely deliver, and refusing them outright would
     * trade a false pass for a false fail. A gate that cries wolf gets
     * bypassed, which leaves you worse off than the hole it closed.
     */
    private const NON_DELIVERING_TRANSPORTS = [
        ArrayTransport::class,
        LogTransport::class,
        NullTransport::class,
    ];

    /** The configured mailer's name, readable in every state including refused ones. */
    public function mailerName(): string
    {
        return (string) config('mail.default');
    }

    /** Convenience inverse of {@see refusal()}, so no caller re-derives it. */
    public function delivers(): bool
    {
        return $this->refusal() === null;
    }

    /**
     * Null when mail can reach a human; otherwise why not.
     */
    public function refusal(): ?MailDeliveryRefusal
    {
        $mailer = $this->mailerName();

        // The NAME first. It runs before the transport is instantiated so the
        // overwhelmingly common `MAIL_MAILER=log` gets its own specific answer
        // without depending on a resolution step that can itself throw.
        if (in_array($mailer, self::NON_DELIVERING_NAMES, true)) {
            return new MailDeliveryRefusal(
                'non_delivering_mailer',
                $mailer,
                "the mailer is '{$mailer}', which accepts every message and delivers none",
            );
        }

        try {
            $transport = Mail::getSymfonyTransport();
        } catch (Throwable $e) {
            // A typo'd MAIL_MAILER, or a driver that is not installed.
            // "Cannot tell" is a no — but a REPORTED no, never an escaping
            // stack trace: a gate must be able to say no legibly.
            return new MailDeliveryRefusal(
                'unresolvable_transport',
                $mailer,
                "the mailer '{$mailer}' could not be resolved to a transport: ".$e->getMessage(),
            );
        }

        $deadEnd = $this->firstNonDeliveringTransport($transport);

        if ($deadEnd !== null) {
            return new MailDeliveryRefusal(
                'non_delivering_chain',
                $mailer,
                "the mailer '{$mailer}' resolves to a chain that reaches '{$deadEnd}', which delivers nothing",
                $deadEnd,
            );
        }

        return null;
    }

    /**
     * Walk a resolved transport tree and return the name of the first member
     * that delivers nothing, or null if every leaf can actually deliver.
     *
     * Returns a NAME rather than a bool on purpose — see MailDeliveryRefusal.
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
     * promoted constructor property with no accessor. The alternative —
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
