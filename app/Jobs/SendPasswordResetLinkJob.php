<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\Http\BackofficeOrigin;
use App\Support\Mail\EmailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * The off-request half of `POST /api/auth/forgot-password`
 * (self-service-password-reset AD-3, AD-5).
 *
 * WHY THIS IS A JOB AND NOT A CONTROLLER BRANCH
 * ---------------------------------------------
 * Every "should we actually send?" decision lives here so the CONTROLLER can be
 * branch-free. If the controller decided, the existing-user path would perform
 * a token write plus a live mail round trip — hundreds of milliseconds — while
 * the unknown-email path returned immediately. That is not a subtle statistical
 * side channel, it is a stopwatch, and it would turn the endpoint into an
 * account-enumeration oracle no matter how identical the response body was.
 *
 * The consequence, stated plainly: the WORKER is now on this feature's critical
 * path. A worker that is not running means no reset emails, while the API keeps
 * answering 202. That is a deployment fact, not a bug in this class — but it is
 * why a failure here is LOUD (logged, and the job is allowed to fail and retry)
 * rather than swallowed.
 *
 * THREE REFUSALS
 * --------------
 * 1. Unknown address — nothing to send to. Not an error; the caller was told
 *    nothing either way.
 * 2. Deactivated user — a reset must never be a reactivation side channel,
 *    mirroring `ResetUserPasswordCommand`'s "refuse, do not reactivate". The
 *    operator sees the refusal in the log; the caller sees the same 202.
 * 3. Unusable `BACKOFFICE_ORIGIN` — refuse rather than send a mail carrying a
 *    broken link. A reset mail whose link goes nowhere is WORSE than no mail:
 *    the user believes recovery is in progress and stops looking for another
 *    route (the CLI, or an admin).
 *
 * The token itself is minted by Laravel's `Password` broker — hashed at rest,
 * single-use, expiring, and per-user throttled (`config/auth.php`
 * passwords.users) — and is never logged here or anywhere else.
 */
final class SendPasswordResetLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $email) {}

    /**
     * Declared explicitly, never inherited: a job that declares nothing takes
     * whatever `--tries` the worker was started with
     * (QueuedJobRetryOwnershipArchTest).
     *
     * Three, with backoff, because the failure this retries is a transient mail
     * provider hiccup and the person waiting cannot see it. It must NOT retry
     * forever either: the reset token is minted BEFORE the send, so a job that
     * kept retrying past the token's own TTL would deliver a link that was
     * already dead on arrival.
     */
    public function tries(): int
    {
        return 3;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Well inside the token TTL, and generous enough for one Resend round trip
     * on a slow day.
     */
    public function timeout(): int
    {
        return 30;
    }

    public function handle(): void
    {
        // Unscoped by design, exactly as `ResetUserPasswordCommand` reads it:
        // there is no tenant context on an unauthenticated request, `users.email`
        // is globally unique, and this must also reach platform superadmins
        // (organization_id IS NULL).
        $user = User::query()->where('email', $this->email)->first();

        if ($user === null) {
            // Deliberately silent about WHICH address: a log line naming every
            // probed address is a ready-made enumeration list for anyone with
            // log access.
            return;
        }

        if ($user->isDeactivated()) {
            Log::info('password reset refused: user is deactivated', ['user_id' => $user->id]);

            return;
        }

        $origin = BackofficeOrigin::resolve('PasswordReset');

        if ($origin === null) {
            Log::error(
                'password reset NOT sent: BACKOFFICE_ORIGIN is unset or invalid, so no reset link can be built. '
                .'Set BACKOFFICE_ORIGIN on the api service. Recovery is still available via beai:reset-user-password.',
                ['user_id' => $user->id],
            );

            return;
        }

        // The broker owns hashing-at-rest, single-use consumption, expiry and
        // its own per-user throttle (`config/auth.php` passwords.users.throttle,
        // 60s). That throttle is per-USER and cannot price an HTTP endpoint —
        // the route throttle does that — but it IS what stops a repeated
        // request from mail-bombing one inbox, and it runs here rather than
        // in the request precisely because of AD-3.
        // Branded like the other two. A superadmin has no organization, so
        // `find(null)` returns null and the message renders in the product's
        // own colour — which is the correct answer, not a gap.
        // ONE lookup, both fields. The colour and the name are the same
        // decision — who this message is from — so splitting them into two
        // reads would be two chances for them to disagree.
        $organization = Organization::withoutGlobalScopes()->find($user->organization_id);

        $branding = app(EmailBranding::class);
        $branding->set($organization?->primary_color);
        $branding->setOrganizationName($organization?->name);

        $token = Password::broker()->createToken($user);

        $expiresInMinutes = (int) config('auth.passwords.users.expire');

        // Locale from the TARGET USER, never the ambient one: the queue worker
        // runs in whatever locale it booted with, and i18n is mandatory it/en.
        $user->notify(
            (new ResetPasswordNotification(
                $origin.'/reset-password/'.$token.'?email='.urlencode($user->email),
                $expiresInMinutes,
            ))->locale($user->locale ?? (string) config('app.locale'))
        );

        $branding->forget();
    }
}
