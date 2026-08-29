<?php

declare(strict_types=1);

/**
 * The off-request send: `SendPasswordResetLinkJob` and
 * `ResetPasswordNotification` (self-service-password-reset AD-3, AD-5, AD-6,
 * AD-8).
 *
 * The job is where every "should we actually send?" decision lives, precisely
 * so the controller can be branch-free and therefore timing-flat. Three
 * refusals matter:
 *   - unknown address       → nothing to send to
 *   - deactivated user      → a reset must never be a reactivation side channel
 *   - unusable link origin  → a mail carrying a broken link is worse than no
 *                             mail: the user believes recovery is in progress
 *                             and stops looking for another route
 *
 * REQ: password-recovery mail delivery
 */

use App\Jobs\SendPasswordResetLinkJob;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;

function mailTestUser(array $attributes = []): User
{
    $org = Organization::factory()->create();

    return User::factory()->create(array_merge([
        'organization_id' => $org->id,
        'email' => 'mail@example.com',
        'locale' => 'en',
    ], $attributes));
}

beforeEach(function (): void {
    config()->set('services.backoffice_origin', 'https://backoffice.example.com');
});

test('the job sends a reset notification to a known, active user', function (): void {
    Notification::fake();
    $user = mailTestUser();

    (new SendPasswordResetLinkJob($user->email))->handle();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('the job sends nothing for an unknown address, and does not throw', function (): void {
    Notification::fake();

    (new SendPasswordResetLinkJob('nobody@example.com'))->handle();

    Notification::assertNothingSent();
});

test('the job sends nothing for a DEACTIVATED user — a reset is not a reactivation', function (): void {
    Notification::fake();
    $user = mailTestUser(['deactivated_at' => now()]);

    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $lines[] = ((string) ($message->message ?? '')).' '.$context;
    });

    (new SendPasswordResetLinkJob($user->email))->handle();

    Notification::assertNothingSent();

    // The refusal is logged BY ID, never by address. Until this assertion
    // existed the delta spec's "A deactivated refusal is logged by id, not
    // address" scenario was claimed rather than held: the job is at 100% line
    // coverage, and changing the context to `['email' => $user->email]` would
    // have turned nothing red. Line coverage is not assertion coverage.
    //
    // It matters because this is the one branch that has PROVEN the address
    // belongs to a real account. A log naming it is the enumeration list the
    // whole flow is shaped to avoid — the endpoint refuses to be an oracle,
    // and a log line here would hand the same answer to anyone with log access.
    $logged = implode("\n", $lines);

    expect($logged)->toContain('password reset refused: user is deactivated')
        ->and($logged)->toContain((string) $user->id)
        ->and($logged)->not->toContain($user->email);
});

test('the job REFUSES to send when the backoffice origin is unset, and logs the refusal', function (): void {
    Notification::fake();
    config()->set('services.backoffice_origin', '');
    $user = mailTestUser();

    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $lines[] = (string) ($message->message ?? '');
    });

    (new SendPasswordResetLinkJob($user->email))->handle();

    Notification::assertNothingSent();
    expect(implode("\n", $lines))->toContain('password reset');
});

test('the job refuses a wildcard or non-origin BACKOFFICE_ORIGIN rather than guessing', function (string $origin): void {
    Notification::fake();
    config()->set('services.backoffice_origin', $origin);

    $user = mailTestUser();
    (new SendPasswordResetLinkJob($user->email))->handle();

    Notification::assertNothingSent();
})->with(['*', 'backoffice.example.com', 'ftp://backoffice.example.com']);

test('the job is queued — the send never happens inside the HTTP request', function (): void {
    // AD-3: this is the structural fix for the timing oracle, not a
    // performance choice.
    expect(is_subclass_of(SendPasswordResetLinkJob::class, ShouldQueue::class))->toBeTrue();
});

test('the notification is NOT queued — the arch guard forbids it with no allowlist', function (): void {
    // C12's NotificationNeverQueuedArchTest has no allowlist. The queue
    // boundary is the job above; the notification is a pure renderer.
    expect(is_subclass_of(ResetPasswordNotification::class, ShouldQueue::class))->toBeFalse();
});

/**
 * The message the ARRAY transport actually collected — a real render through
 * the real Blade views, not a stubbed Mailable. `phpunit.xml` pins
 * MAIL_MAILER=array, so nothing leaves the process and nothing is faked away.
 * `Mail::fake()` would be worse here: it intercepts before the notification
 * channel builds a message at all, so the views under test would never run.
 */
function sentResetEmail(): Email
{
    $messages = Mail::mailer()->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1);

    /** @var Email $email */
    $email = $messages->first()->getOriginalMessage();

    return $email;
}

test('the mail carries the reset link built from the backoffice origin, and the same URL as plain text', function (): void {
    $user = mailTestUser();

    (new SendPasswordResetLinkJob($user->email))->handle();

    $html = (string) sentResetEmail()->getHtmlBody();

    expect($html)->toContain('https://backoffice.example.com/reset-password/');

    // AD-6: the full URL appears as plain text below the button. A button
    // whose href is the only copy of the link is unusable in a client that
    // strips or mangles anchors, and unverifiable by a cautious reader who
    // wants to see where a security-sensitive link goes before clicking it.
    expect(substr_count($html, 'https://backoffice.example.com/reset-password/'))
        ->toBeGreaterThanOrEqual(2);
});

test('the mail contains no remote image — it must render with nothing fetched', function (): void {
    $user = mailTestUser();

    (new SendPasswordResetLinkJob($user->email))->handle();

    $html = (string) sentResetEmail()->getHtmlBody();

    expect($html)->not->toMatch('/<img[^>]+src=["\']https?:\/\//i');
});

test('the mail is legible with CSS stripped entirely — the link survives as text', function (): void {
    $user = mailTestUser();

    (new SendPasswordResetLinkJob($user->email))->handle();

    $email = sentResetEmail();

    // Strip every <style> block and every style= attribute, then assert the
    // message still says what it is and still carries its link. Gmail strips
    // CSS aggressively and Outlook renders with Word; a mail that only works
    // styled is a mail a locked-out user cannot act on.
    $stripped = (string) preg_replace(
        ['/<style\b[^>]*>.*?<\/style>/is', '/\sstyle="[^"]*"/i'],
        '',
        (string) $email->getHtmlBody(),
    );

    expect($stripped)->toContain('https://backoffice.example.com/reset-password/');
    expect(strip_tags($stripped))->toContain(__('password_reset.line_1', [], 'en'));

    // The plain-text part is present and complete, not an afterthought.
    $text = (string) $email->getTextBody();
    expect($text)->toContain('https://backoffice.example.com/reset-password/');
});

test('the mail is rendered in the target user locale, not the request locale', function (): void {
    app()->setLocale('en');
    $user = mailTestUser(['locale' => 'it']);

    (new SendPasswordResetLinkJob($user->email))->handle();

    $html = (string) sentResetEmail()->getHtmlBody();

    // i18n is mandatory it/en, and the recipient's language is the USER's,
    // never whichever locale the queue worker happened to boot in.
    expect($html)->toContain(__('password_reset.line_1', [], 'it'));
    expect($html)->not->toContain(__('password_reset.line_1', [], 'en'));
});

test('the mail writes no notification_logs row — this is not a C12 trigger', function (): void {
    $user = mailTestUser();

    (new SendPasswordResetLinkJob($user->email))->handle();

    // AD-8: `notifications` has exactly two triggers, and a self-service
    // reset is neither rare nor operator-facing. Reusing that pipeline would
    // dissolve a deliberately narrow invariant.
    expect(NotificationLog::withoutGlobalScopes()->count())->toBe(0);
});

test('the sender is the configured from address', function (): void {
    config()->set('mail.from.address', 'noreply@quint.org');
    config()->set('mail.from.name', 'BEAI');
    $user = mailTestUser();

    (new SendPasswordResetLinkJob($user->email))->handle();

    expect(sentResetEmail()->getFrom()[0]->getAddress())->toBe('noreply@quint.org');
});

test('an empty RESEND_API_KEY does not break the flow — local dev and CI need no Resend account', function (): void {
    // `MAIL_MAILER` is `array` here (phpunit.xml) and `smtp`/Mailpit locally.
    // Neither transport reads `services.resend.key`, so an unset key is a
    // WORKING default rather than a missing prerequisite. Only setting
    // MAIL_MAILER=resend makes the key required — a deployment decision.
    config()->set('services.resend.key', null);
    $user = mailTestUser();

    (new SendPasswordResetLinkJob($user->email))->handle();

    expect((string) sentResetEmail()->getHtmlBody())
        ->toContain('https://backoffice.example.com/reset-password/');
});

test('the reset token never reaches a log channel from the send path either', function (): void {
    $user = mailTestUser();

    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $lines[] = ((string) ($message->message ?? '')).' '.$context;
    });

    (new SendPasswordResetLinkJob($user->email))->handle();

    // Recover the token the mail actually carries, then prove it appears in no
    // log line. The token is a bearer credential for the account: a log
    // aggregator that captured it would be a password-reset oracle for anyone
    // with log access.
    preg_match('#/reset-password/([^?"\s]+)#', (string) sentResetEmail()->getHtmlBody(), $matches);

    expect($matches[1] ?? '')->not->toBe('');

    // Asserted over the JOINED lines rather than in a loop. A `foreach` here
    // executes zero assertions whenever the job happens to log nothing, and
    // the preceding assertion masks that — the test stays green while the
    // property it names goes unchecked. One assertion over the concatenation
    // always runs, and fails the same way if the token ever appears.
    expect(implode("\n", $lines))->not->toContain($matches[1]);
});

test('the job logs no probed address for an unknown email — logs must not become an enumeration list', function (): void {
    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $lines[] = ((string) ($message->message ?? '')).' '.$context;
    });

    (new SendPasswordResetLinkJob('probe-target@example.com'))->handle();

    // An unknown address makes the job return silently, so `$lines` is empty
    // and a bare `foreach` over it executes ZERO assertions — this test was
    // the only risky one in a 2603-test suite for exactly that reason. It
    // would still have caught a newly added log line naming the address, but
    // a test guarding "logs must not become an enumeration list" should not
    // depend on the reader noticing it asserts nothing today.
    //
    // Asserting emptiness first is strictly stronger AND always a real
    // assertion: it fails if the job starts logging anything at all on the
    // unknown-address path, which is the earliest signal that the silence
    // this requirement depends on has been broken.
    expect($lines)->toBeEmpty();

    foreach ($lines as $line) {
        expect($line)->not->toContain('probe-target@example.com');
    }
});

test('the job owns its retry contract — three tries with backoff, well inside the token TTL', function (): void {
    $job = new SendPasswordResetLinkJob('mail@example.com');

    // A queued job that declares nothing silently inherits whatever `--tries`
    // the worker was started with (QueuedJobRetryOwnershipArchTest). Retrying
    // forever would be worse than not retrying: the token is minted BEFORE the
    // send, so a link delivered past its own TTL is dead on arrival.
    expect($job->tries())->toBe(3);
    expect($job->backoff())->toBe([10, 60]);
    expect($job->timeout())->toBeLessThan((int) config('auth.passwords.users.expire') * 60);
});
