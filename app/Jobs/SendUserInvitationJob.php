<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Support\Http\BackofficeOrigin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Sends the invitation for a user an admin has just created.
 *
 * A JOB, NOT A CONTROLLER BRANCH, for a plainer reason than
 * `SendPasswordResetLinkJob`'s timing-flatness: a live mail round trip inside
 * `POST /api/users` makes creating a user fail when the mail provider is
 * having a bad minute. The account was created successfully; refusing to
 * report that because an email did not go out is the wrong failure to surface.
 *
 * IT MINTS THE TOKEN HERE, NOT IN THE CONTROLLER
 * -----------------------------------------------
 * Password-reset tokens expire, and minting one at request time means the
 * clock starts before a queue that may be backed up. Minting it inside the job
 * means the recipient gets the full TTL from the moment the mail is actually
 * sent.
 *
 * TWO REFUSALS
 * ------------
 * 1. The user no longer exists — deleted between creation and this job
 *    running. Nothing to invite; not an error.
 * 2. Unusable `BACKOFFICE_ORIGIN` — refuse rather than send an invitation
 *    carrying a broken link. An invitation whose link goes nowhere is worse
 *    than none: the recipient believes they have been onboarded and stops
 *    looking for the reason they cannot get in.
 *
 * A failure here is LOUD — logged, and allowed to fail and retry — because
 * nobody is watching a queue. The admin who created the account is told it was
 * created, which it was.
 */
final class SendUserInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $userId,
        private readonly string $role,
        private readonly string $inviterName,
        private readonly string $organizationName,
    ) {}

    /**
     * Declared explicitly, never inherited: a job that declares nothing takes
     * whatever `--tries` the worker was started with
     * (QueuedJobRetryOwnershipArchTest).
     *
     * Three with backoff, matching the reset job: the failure being retried is
     * a transient mail-provider hiccup, and it must not retry past the token's
     * own TTL or it would deliver a link that was dead on arrival.
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

    public function timeout(): int
    {
        return 30;
    }

    public function handle(): void
    {
        // Unscoped, exactly as the reset job reads it: a queue worker carries
        // no tenant context, and the id came from a request that had already
        // authorized the creation.
        $user = User::withoutGlobalScopes()->find($this->userId);

        if ($user === null) {
            Log::info('user invitation skipped: user no longer exists', ['user_id' => $this->userId]);

            return;
        }

        $origin = BackofficeOrigin::resolve('UserInvitation');

        if ($origin === null) {
            Log::error(
                'user invitation NOT sent: BACKOFFICE_ORIGIN is unset or invalid, so no set-password link can be built. '
                .'Set BACKOFFICE_ORIGIN on the api service. The account exists and the user can still be reached '
                .'through "Forgot password" once the origin is fixed.',
                ['user_id' => $user->id],
            );

            return;
        }

        $token = Password::broker()->createToken($user);
        $expiresInMinutes = (int) config('auth.passwords.users.expire');

        // Locale from the TARGET USER, never the ambient one: the worker runs
        // in whatever locale it booted with, and i18n is mandatory it/en.
        $user->notify(
            (new UserInvitationNotification(
                $origin.'/reset-password/'.$token.'?email='.urlencode($user->email),
                $expiresInMinutes,
                $this->role,
                $this->inviterName,
                $this->organizationName,
            ))->locale($user->locale ?? (string) config('app.locale'))
        );
    }
}
