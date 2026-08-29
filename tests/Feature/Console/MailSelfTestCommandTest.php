<?php

declare(strict_types=1);

/**
 * `beai:mail-selftest` — the ship gate for the self-service password reset.
 *
 * The delta spec `openspec/changes/self-service-password-reset/specs/
 * password-recovery/spec.md`, requirement *"The Flow Is Inert Without A
 * Delivering Mail Transport, And The Probe Refuses To Pretend Otherwise"*,
 * makes this command the evidence that enables the feature in production.
 * Until this file existed the command had ZERO coverage — an unverified
 * verifier, which is the same class of failure it was written to catch.
 *
 * WHAT IS ACTUALLY ASSERTED, AND WHY IT IS NOT THE EXIT CODE ALONE
 * ---------------------------------------------------------------
 * An exit code proves the command said no. It does NOT prove the command
 * said no *before spending a send* — and "refused after already handing a
 * message to a transport that swallows it" is exactly the outcome the
 * command exists to make impossible. So every refusal here is asserted
 * twice: the non-zero code, and that `Mail::raw` was never reached.
 *
 * `Mail::fake()` CANNOT make that second assertion. `MailFake::raw()` is an
 * empty method body (framework/src/Illuminate/Support/Testing/Fakes/
 * MailFake.php:484) — it records nothing, so `assertNothingSent()` passes
 * whether or not the command sent, on every path. That is a test that cannot
 * fail. `Mail::spy()` is used instead, on the paths that refuse before the
 * manager is consulted: a Mockery spy over the real MailManager records the
 * `raw` call without performing it, so `shouldNotHaveReceived('raw')`
 * genuinely fails when the guard is removed. Past that point the command
 * resolves the transport through the manager, which a spy answers with null,
 * so the later paths assert on a real recording transport instead — stronger
 * anyway, being the transport itself reporting what it was handed.
 *
 * Where a real message is wanted, the configured mailer keeps its real NAME
 * (`smtp`, `resend`) and its transport is the recording probe below. It is NOT
 * the array transport: the command now refuses whatever resolves to
 * `ArrayTransport` under any name at all, so a seam that relied on the gate
 * being fooled by a label would be testing the hole rather than the gate.
 *
 * ORDERING IS PINNED ON PURPOSE
 * -----------------------------
 * The refusals run: missing `--to` → non-delivering mailer NAME → the
 * resolved TRANSPORT → default `from`. A reordering would let a run get
 * further than it should before refusing, and no single-refusal test would
 * notice, so three tests assert a refusal fires while the NEXT one's message
 * is absent.
 *
 * A TRAP IN `expectsOutputToContain`
 * ----------------------------------
 * Each call registers its own Mockery `doWrite` expectation and Mockery
 * dispatches a write to the FIRST matching expectation only
 * (Illuminate/Testing/PendingCommand.php:615-623). Two `expectsOutputToContain`
 * calls satisfied by the SAME output line therefore fail the second, reporting
 * "Output does not contain X" about a string that is plainly there. Assert one
 * substring per line.
 *
 * No DB is touched. `Feature/Console` already carries `RefreshDatabase`
 * (tests/Pest.php, for ForgetLocaleCommandTest's real seeder) and no new
 * binding is required: this file writes no rows, so it has nothing to leak.
 */

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** A `from` that is neither empty nor the framework default, so step 2 passes. */
const SELFTEST_FROM = 'ops@beai.test';

/**
 * A transport that delivers, as far as the gate can tell, and records what it
 * is handed.
 *
 * The array transport CANNOT be used for this any more, and that is the whole
 * point of the change these tests cover: the command now refuses whatever
 * resolves to `ArrayTransport`, under any mailer name. A test seam that only
 * worked because the gate was fooled by a label would be testing the hole.
 * This is a genuinely unrecognised transport instead, registered through the
 * public `Mail::extend()` API.
 */
final class RecordingProbeTransport implements Stringable, TransportInterface
{
    /** @var list<SentMessage> */
    public static array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return self::$sent[] = new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'beai-probe';
    }
}

/** A delivering transport whose send fails the way a provider rejection does. */
final class ThrowingProbeTransport implements Stringable, TransportInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException('Resend API error: domain is not verified');
    }

    public function __toString(): string
    {
        return 'beai-throwing-probe';
    }
}

beforeEach(function (): void {
    RecordingProbeTransport::$sent = [];
    Mail::extend('beai-probe', fn (): TransportInterface => new RecordingProbeTransport);
    Mail::extend('beai-throwing-probe', fn (): TransportInterface => new ThrowingProbeTransport);
});

/**
 * Point the named mailer at the recording probe while KEEPING its name, so
 * the `smtp` / `resend` tail branches still resolve on the real config key
 * the command reads.
 */
function useDeliveringMailerNamed(string $name): void
{
    config([
        'mail.default' => $name,
        "mail.mailers.{$name}" => ['transport' => 'beai-probe'],
        'mail.from.address' => SELFTEST_FROM,
        'mail.from.name' => 'BEAI Ops',
    ]);
}

/** @return list<SentMessage> */
function selfTestSentMessages(): array
{
    return RecordingProbeTransport::$sent;
}

// ─── The non-delivering transports ────────────────────────────────────────────
// Spec scenario: "The probe refuses a non-delivering transport".

test('it refuses the log transport, names it as delivering nothing, and exits non-zero', function (): void {
    config(['mail.default' => 'log', 'mail.from.address' => SELFTEST_FROM]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain("MAIL_MAILER is 'log' — this transport delivers NOTHING.")
        ->assertExitCode(Command::FAILURE);
});

test('it refuses the array transport too — the list is both entries, not just log', function (): void {
    // `array` is the transport phpunit.xml pins for the suite. If only `log`
    // were guarded, every CI run would be a green light over a no-op.
    config(['mail.default' => 'array', 'mail.from.address' => SELFTEST_FROM]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain("MAIL_MAILER is 'array' — this transport delivers NOTHING.")
        ->assertExitCode(Command::FAILURE);
});

test('on a non-delivering transport NO mail is attempted — the refusal precedes the send', function (): void {
    // The property that gives the command its value. An exit code alone
    // would also be satisfied by "sent it into the void, then complained".
    Mail::spy();
    config(['mail.default' => 'log', 'mail.from.address' => SELFTEST_FROM]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->assertExitCode(Command::FAILURE);

    Mail::shouldNotHaveReceived('raw');
});

test('the suite\'s own pinned transport is refused by the probe', function (): void {
    // Spec scenario: "CI proves correctness without proving deliverability".
    // No config override — this is phpunit.xml's `MAIL_MAILER=array` as the
    // whole suite runs it. A passing suite therefore cannot be read as
    // evidence that production mail delivers, and the probe says so.
    expect(config('mail.default'))->toBe('array');

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('this transport delivers NOTHING')
        ->assertExitCode(Command::FAILURE);
});

// ─── The required recipient ───────────────────────────────────────────────────

test('--to is required: without it the command refuses and sends nothing', function (): void {
    Mail::spy();
    useDeliveringMailerNamed('smtp');

    $this->artisan('beai:mail-selftest')
        ->expectsOutputToContain('--to is required. Nothing was sent.')
        ->assertExitCode(Command::FAILURE);

    Mail::shouldNotHaveReceived('raw');
});

test('a --to of only whitespace is refused as absent, not sent to an empty address', function (): void {
    Mail::spy();
    useDeliveringMailerNamed('smtp');

    $this->artisan('beai:mail-selftest', ['--to' => '   '])
        ->expectsOutputToContain('--to is required. Nothing was sent.')
        ->assertExitCode(Command::FAILURE);

    Mail::shouldNotHaveReceived('raw');
});

// ─── The sender ───────────────────────────────────────────────────────────────
// Spec scenario: "The probe refuses a default or unset sender before spending
// a send".

// These two no longer use `Mail::spy()`. `Mail::spy()` swaps the MailManager
// for a Mockery spy, and the command now asks the manager for the resolved
// transport — which a spy answers with null. A spy is therefore no longer a
// usable stand-in past the mailer-name check. Asserting on the real recording
// transport is strictly stronger anyway: it is the transport itself reporting
// that it was handed nothing, not a mock reporting that a method went uncalled.

test('it refuses the framework default from address before attempting delivery', function (): void {
    useDeliveringMailerNamed('smtp');
    config(['mail.from.address' => 'hello@example.com']);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain("MAIL_FROM_ADDRESS is unset or still the framework default ('hello@example.com').")
        ->assertExitCode(Command::FAILURE);

    expect(selfTestSentMessages())->toBeEmpty();
});

test('it refuses an empty from address before attempting delivery', function (): void {
    useDeliveringMailerNamed('smtp');
    config(['mail.from.address' => '']);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('MAIL_FROM_ADDRESS is unset or still the framework default')
        ->assertExitCode(Command::FAILURE);

    expect(selfTestSentMessages())->toBeEmpty();
});

// ─── What it reports ──────────────────────────────────────────────────────────

test('it reports the resolved mailer, the configured from, and the recipient', function (): void {
    useDeliveringMailerNamed('smtp');

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('mailer:  smtp')
        ->expectsOutputToContain('from:    BEAI Ops <ops@beai.test>')
        ->expectsOutputToContain('to:      operator@beai.test')
        ->assertExitCode(Command::SUCCESS);
});

test('it reports the mailer even when it is about to refuse it', function (): void {
    // The report is the diagnostic. Printing it only on the happy path would
    // withhold the one fact an operator needs at the moment it goes wrong.
    config(['mail.default' => 'log', 'mail.from.address' => SELFTEST_FROM]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('mailer:  log')
        ->assertExitCode(Command::FAILURE);
});

// ─── Refusal ORDER ────────────────────────────────────────────────────────────

test('a missing --to is refused BEFORE the transport is judged', function (): void {
    // Order pin 1 of 2. With a `log` mailer AND no --to, both guards would
    // fire on their own; the command must stop at the first. If they were
    // reordered every single-guard test above would still pass.
    config(['mail.default' => 'log', 'mail.from.address' => SELFTEST_FROM]);

    $this->artisan('beai:mail-selftest')
        ->expectsOutputToContain('--to is required. Nothing was sent.')
        ->doesntExpectOutputToContain('this transport delivers NOTHING')
        ->assertExitCode(Command::FAILURE);
});

test('a non-delivering transport is refused BEFORE the from address is judged', function (): void {
    // Order pin 2 of 2. Both are wrong here; the transport is the one that
    // must be named, because it is the failure that is silent in production.
    config(['mail.default' => 'array', 'mail.from.address' => 'hello@example.com']);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('this transport delivers NOTHING')
        ->doesntExpectOutputToContain('MAIL_FROM_ADDRESS is unset or still the framework default')
        ->assertExitCode(Command::FAILURE);
});

// ─── The send itself ──────────────────────────────────────────────────────────

test('a delivering transport gets exactly one message, addressed to --to, carrying the reported token', function (): void {
    useDeliveringMailerNamed('smtp');

    $exitCode = Artisan::call('beai:mail-selftest', ['--to' => ' operator@beai.test ']);
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::SUCCESS);

    $messages = selfTestSentMessages();
    expect($messages)->toHaveCount(1);

    $email = $messages[0]->getOriginalMessage();
    assert($email instanceof Email);

    // --to is trimmed before use: a shell that hands over a padded value must
    // not produce a message addressed to " operator@beai.test ".
    expect(array_map(static fn ($address) => $address->getAddress(), $email->getTo()))
        ->toBe(['operator@beai.test'])
        ->and(array_map(static fn ($address) => $address->getAddress(), $email->getFrom()))
        ->toBe([SELFTEST_FROM]);

    // The token printed to the operator is the token IN the message — that
    // correspondence is the whole point of printing it, since finding it in an
    // inbox is how a human confirms arrival.
    expect($output)->toMatch('/Sent\. token [0-9a-f-]{36}/');
    preg_match('/Sent\. token ([0-9a-f-]{36})/', $output, $matches);

    expect($email->getSubject())->toBe("BEAI mail self-test {$matches[1]}")
        ->and((string) $email->getTextBody())->toContain("token:  {$matches[1]}");
});

test('a transport that throws is reported as a failure, not as a send', function (): void {
    // Resend rejects an unverified from-domain inside the send call, not at
    // boot. If that escaped uncaught the operator would get a stack trace
    // instead of the one sentence that names the likely cause.
    // Was a `Mail::shouldReceive('raw')->andThrow()` facade mock. A full facade
    // mock also blanks the transport resolution the command now performs, so
    // the throw is staged in a real transport instead — which exercises the
    // actual send path rather than a mocked one.
    config([
        'mail.default' => 'resend',
        'mail.mailers.resend' => ['transport' => 'beai-throwing-probe'],
        'mail.from.address' => SELFTEST_FROM,
    ]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('Send FAILED: Resend API error: domain is not verified')
        ->doesntExpectOutputToContain('Sent. token')
        ->assertExitCode(Command::FAILURE);
});

// ─── The success message never overstates what was proved ─────────────────────

test('an smtp success says Mailpit captured it, and does NOT claim production mail works', function (): void {
    useDeliveringMailerNamed('smtp');

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('open Mailpit at http://localhost:8025')
        ->expectsOutputToContain('Mailpit CAPTURES mail; it does not deliver it.')
        ->assertExitCode(Command::SUCCESS);
});

test('a provider success says accepted is not the same as arrived', function (): void {
    // Spec: the probe "MUST state plainly that acceptance by a provider is not
    // the same as arrival in an inbox".
    useDeliveringMailerNamed('resend');

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('Check the operator@beai.test inbox for the token above')
        ->expectsOutputToContain('Accepted by the API is not the same as landed in an inbox')
        ->assertExitCode(Command::SUCCESS);
});

test('every success tells the operator to run it on the worker service too', function (): void {
    // The api and worker are separate Railway services with separate variable
    // sets, and the worker is the one that sends operator alerts. A pass on
    // api alone proves nothing about the sender that matters.
    useDeliveringMailerNamed('smtp');

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('Run this on the WORKER service too.')
        ->assertExitCode(Command::SUCCESS);
});

// ─── The resolved transport, not the configured name ──────────────────────────
//
// Added 2026-08-28 after `MAIL_MAILER=failover` was demonstrated printing
// `Sent.` and exiting 0 on a chain that delivered nothing. `failover` is a
// STOCK mailer in config/mail.php:82-89 whose default members are
// ['smtp', 'log'] — Symfony's failover transport falls through to `log` the
// moment smtp fails, and `failover` is not a name on NON_DELIVERING. The gate
// therefore has to judge the transport that actually resolves.

test('a failover chain that falls through to log is refused, and log is named', function (): void {
    // The exact stock shape from config/mail.php. Nothing here is contrived:
    // this is what `MAIL_MAILER=failover` means today.
    config([
        'mail.default' => 'failover',
        'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log'], 'retry_after' => 60],
        'mail.from.address' => SELFTEST_FROM,
    ]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        // ONE substring, not two. `expectsOutputToContain` registers a separate
        // Mockery `doWrite` expectation per call and Mockery dispatches a call
        // to the FIRST matching one only (PendingCommand.php:615-623), so two
        // expectations satisfied by the SAME output line fail the second. A
        // single substring also pins the association — that it is `log` which
        // delivers nothing — rather than merely their co-occurrence.
        ->expectsOutputToContain("reaches 'log', which delivers NOTHING")
        ->doesntExpectOutputToContain('Sent. token')
        ->assertExitCode(Command::FAILURE);
});

test('a roundrobin chain containing the array transport is refused too', function (): void {
    // FailoverTransport extends RoundRobinTransport; a walk that only knew the
    // subclass would miss the sibling, and roundrobin is equally stock.
    config([
        'mail.default' => 'roundrobin',
        'mail.mailers.roundrobin' => ['transport' => 'roundrobin', 'mailers' => ['beai-probe-mailer', 'array'], 'retry_after' => 60],
        'mail.mailers.beai-probe-mailer' => ['transport' => 'beai-probe'],
        'mail.from.address' => SELFTEST_FROM,
    ]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain("reaches 'array', which delivers NOTHING")
        ->assertExitCode(Command::FAILURE);
});

test('a composite whose members ALL deliver is NOT refused — the gate must not cry wolf', function (): void {
    // Requirement: failover and roundrobin can genuinely deliver. Refusing them
    // by name would trade a false pass for a false fail, and a gate that cries
    // wolf gets bypassed, which is worse than the hole it closed.
    config([
        'mail.default' => 'failover',
        'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['beai-probe-mailer', 'beai-probe-mailer'], 'retry_after' => 60],
        'mail.mailers.beai-probe-mailer' => ['transport' => 'beai-probe'],
        'mail.from.address' => SELFTEST_FROM,
        'mail.from.name' => 'BEAI Ops',
    ]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('Sent. token')
        ->assertExitCode(Command::SUCCESS);

    expect(RecordingProbeTransport::$sent)->toHaveCount(1);
});

test('a nested composite is walked all the way to the bottom', function (): void {
    // One level of recursion is easy to get right by accident with a single
    // `if`. Two is not.
    config([
        'mail.default' => 'outer',
        'mail.mailers.outer' => ['transport' => 'failover', 'mailers' => ['inner'], 'retry_after' => 60],
        'mail.mailers.inner' => ['transport' => 'failover', 'mailers' => ['log'], 'retry_after' => 60],
        'mail.from.address' => SELFTEST_FROM,
    ]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain("'log'")
        ->assertExitCode(Command::FAILURE);
});

test('a mailer under ANY name that resolves to a non-delivering transport is refused', function (): void {
    // The heart of it: the refusal must follow the transport, not the label.
    // `notifications` is on no list and reads as perfectly ordinary.
    config([
        'mail.default' => 'notifications',
        'mail.mailers.notifications' => ['transport' => 'array'],
        'mail.from.address' => SELFTEST_FROM,
    ]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain("reaches 'array', which delivers NOTHING")
        ->doesntExpectOutputToContain('Sent. token')
        ->assertExitCode(Command::FAILURE);
});

test('a mailer that cannot be resolved at all is refused, never assumed to work', function (): void {
    // `MAIL_MAILER=smpt` is a plausible typo. Before this, the exception
    // escaped the command as a stack trace; a gate must answer "no", legibly.
    config(['mail.default' => 'no-such-mailer', 'mail.from.address' => SELFTEST_FROM]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('could not be resolved')
        ->assertExitCode(Command::FAILURE);
});

test('a mailer naming an unsupported driver is refused the same way', function (): void {
    config([
        'mail.default' => 'bespoke',
        'mail.mailers.bespoke' => ['transport' => 'carrier-pigeon'],
        'mail.from.address' => SELFTEST_FROM,
    ]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('could not be resolved')
        ->assertExitCode(Command::FAILURE);
});

test('the resolved-transport refusal runs AFTER the name check and BEFORE the from check', function (): void {
    // Order pin 3 of 3. Both the transport and the from are wrong here; the
    // transport is the one that must be named, for the same reason as order
    // pin 2 — it is the failure that is silent in production.
    config([
        'mail.default' => 'notifications',
        'mail.mailers.notifications' => ['transport' => 'array'],
        'mail.from.address' => 'hello@example.com',
    ]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain('delivers NOTHING')
        ->doesntExpectOutputToContain('MAIL_FROM_ADDRESS is unset or still the framework default')
        ->assertExitCode(Command::FAILURE);
});

test('a non-delivering transport is never even resolved when the mailer NAME already fails', function (): void {
    // The name check stays first and stays cheap: `MAIL_MAILER=log` is the
    // overwhelmingly common case and must not depend on a transport
    // instantiation that could itself throw.
    config(['mail.default' => 'log', 'mail.from.address' => SELFTEST_FROM]);

    $this->artisan('beai:mail-selftest', ['--to' => 'operator@beai.test'])
        ->expectsOutputToContain("MAIL_MAILER is 'log' — this transport delivers NOTHING.")
        ->doesntExpectOutputToContain('could not be resolved')
        ->assertExitCode(Command::FAILURE);
});
