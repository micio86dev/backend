<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Reset an EXISTING user's password, given their email, when no in-app
 * recovery path exists.
 *
 * DISTINCT FROM beai:provision-organization, which creates a NEW organization
 * plus its first admin and refuses when either already exists. This command
 * never creates a user and never grants a role — it only resets a password
 * for a user that is already there. See beai:provision-organization for a
 * deployment with no admin at all.
 *
 * Every input is an argument, not a prompt: the place this command is most
 * needed — a production container reached over `railway ssh` — has no TTY,
 * and this command does NOT implement PromptsForMissingInput, so a missing
 * argument fails loudly instead of prompting.
 *
 * NOT final (same reason as ProvisionOrganizationCommand / QueueWorkCommand):
 * `generatePassword()` is a deliberate override seam so a test can supply a
 * password containing Symfony OutputFormatter-significant characters
 * (`<`, `>`, `\`) without depending on a random draw.
 */
class ResetUserPasswordCommand extends Command
{
    protected $signature = 'beai:reset-user-password
        {email : The existing user\'s email address}
        {--operator= : Free-text identity of the person running this reset (e.g. a name or email). NOT verified — this is a break-glass CLI tool with no login of its own, so this is the only accountability signal an operator can choose to leave; recorded as-is, or omitted entirely, in the audit trail}';

    protected $description = "Reset an EXISTING user's password (use beai:provision-organization for a deployment with no admin)";

    protected $help = <<<'HELP'
        Resets the password of an EXISTING user identified by email. The
        password is always generated (never supplied) and printed exactly
        once. This revokes every session the user currently holds.

        Every reset is recorded in the audit trail (target user, email,
        organization, timestamp). --operator is an optional, unverified
        free-text identity for the caller, since this tool has no login of
        its own to derive one from.

        Refuses when:
          - no user exists with the given email
          - the user is deactivated (reactivate first, then re-run)

        Which command owns which situation:
          - beai:reset-user-password    A user exists but cannot log in.
          - beai:provision-organization A deployment has no organization and
                                         therefore no admin at all.
        HELP;

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        // Unscoped by design: TenantContext is HTTP middleware and never runs
        // in CLI, users.email is globally unique, and this must also reach
        // platform superadmins (organization_id IS NULL).
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}]. This command resets an EXISTING user — to bootstrap a deployment with no admin at all, use beai:provision-organization.");

            return self::FAILURE;
        }

        if ($user->isDeactivated()) {
            // Refused, not reactivated: a reset request is not a reactivation
            // decision, and UserGuards treats a deactivated admin as not
            // counting toward the last-admin guard. deactivated_at is
            // untouched — this guard runs before any write.
            $this->error("User [{$email}] is deactivated. Reactivate this user first, then re-run this command.");

            return self::FAILURE;
        }

        $password = $this->generatePassword();

        try {
            // Guards already ran above, before this transaction opens — they
            // are reads, and a refusal must not open one. One save() writes
            // both columns in a single UPDATE.
            //
            // Honest note: a single UPDATE on one model is already atomic at
            // statement level, so DB::transaction() buys nothing TODAY. It is
            // kept because it makes atomicity a property of the code rather
            // than of the accident that both columns sit on one model, and
            // because it is the boundary any later addition (audit row, jti
            // denylist) must land inside — not because this one write needs it.
            DB::transaction(function () use ($user, $password): void {
                // Assigned as plaintext: the model's `hashed` cast hashes it
                // on save.
                $user->password = $password;
                // Same mechanism as ProfileController/UserController:
                // stamping this is what makes RejectStaleCredentials reject
                // every token issued before this write, regardless of
                // remaining TTL. `startOfSecond()` matches the existing
                // writers — `iat` is second-precision, and the comparison in
                // RejectStaleCredentials is a strict `<` inherited unchanged,
                // not tightened for this CLI path.
                $user->password_changed_at = now()->startOfSecond();
                $user->save();
            });
        } catch (Throwable $e) {
            // The transaction has already rolled back, so nothing partial
            // survives. What the operator needs is the reason, not a trace.
            $this->error("Reset failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Placement, not just intent, is what keeps a credential for a rolled
        // back write from ever reaching the operator: report() is called
        // AFTER the transaction returns, never from inside its closure.
        // recordAudit() shares the same placement and the same reason: an
        // audit row (or log line) for a write that then rolled back would be
        // a false positive in exactly the record a security review wanted to
        // exist — it must never claim a reset happened when it did not.
        //
        // KNOWN CEILING (verification finding, 2026-08-18, extended to
        // recordAudit() below): the test suite does not — and structurally
        // cannot with the current fixture — catch a regression that moves
        // either call back inside the closure, AFTER save(). The rollback
        // test (`a failure after the write rolls back...`) throws from an
        // `eloquent.saved` listener, which fires SYNCHRONOUSLY during save()
        // and unwinds the closure immediately; no code placed after save()
        // inside the closure would run either way, so that test cannot
        // distinguish "run after commit" from "run after save(), still
        // inside the closure, still before commit". Catching that regression
        // would need a fixture that lets save() succeed but a LATER
        // statement in the same transaction fail — there isn't one here
        // (this is a one-statement transaction by design, D3). Left as a
        // documented gap rather than an invented fixture that outgrows what
        // this command actually does.
        $this->recordAudit($user, $this->operator());
        $this->report($user, $password);

        return self::SUCCESS;
    }

    /**
     * Normalises `--operator` to a trimmed, non-empty string or null.
     *
     * NOT verified against anything — this command has no login of its own
     * (D per the class docblock: every argument is CLI input, never a
     * session). It is the operator's own free-text claim, offered on a
     * break-glass tool run over `railway ssh` inside a container that always
     * runs as the SAME fixed OS user (Dockerfile: `appuser`, uid 1001) no
     * matter which human is at the keyboard — so `posix_getpwuid()`,
     * `get_current_user()`, `$_SERVER['USER']` and `SUDO_USER` would all
     * resolve to that one constant string for every operator, which is not
     * accountability, it just LOOKS like it. `--operator` is recorded
     * honestly as "whatever the caller chose to type, or nothing" rather
     * than inventing a signal that always says the same thing.
     */
    private function operator(): ?string
    {
        $operator = $this->option('operator');

        if (! is_string($operator)) {
            return null;
        }

        $operator = trim($operator);

        return $operator === '' ? null : $operator;
    }

    /**
     * Records that this reset happened (C13) — never the password.
     *
     * Reuses the existing sanctioned mechanism (AuditRecorder + audit_logs,
     * `App\Support\Audit\AuditRecorder`) rather than inventing a second one.
     * `audit_logs.organization_id` is a NOT NULL foreign key
     * (`2026_07_31_000003_create_audit_logs_table.php`) because the table is
     * tenant-scoped by design (TenantModel/TenantScoped) — every OTHER row
     * in it belongs to exactly one organization, and a dashboard reading
     * "what happened in this org" depends on that being true unconditionally.
     *
     * A platform superadmin (organization_id IS NULL) breaks that
     * assumption: there is no tenant for the row to belong to.
     * `TenantContextScope::runFor()` (the sanctioned way to establish tenant
     * context outside an HTTP request — jobs, listeners, console commands)
     * refuses orgId < 1 outright, and faking a real org's id here would
     * misattribute a superadmin's password reset into that org's audit
     * trail — worse than not recording it in this table at all. Widening
     * the column to nullable would fix this ONE caller by weakening the
     * invariant every other row in the table depends on. Neither is
     * acceptable, so the platform-superadmin case falls back to the
     * application log instead — a deliberate, visible fallback, not a
     * silent gap.
     */
    private function recordAudit(User $user, ?string $operator): void
    {
        $after = [
            'email' => $user->email,
            'operator' => $operator,
        ];

        if ($user->organization_id !== null) {
            TenantContextScope::runFor(
                $user->organization_id,
                fn () => app(AuditRecorder::class)->record(
                    action: 'user.password_reset',
                    subjectType: 'user',
                    subjectId: $user->id,
                    after: $after,
                ),
            );

            return;
        }

        Log::notice('audit.user.password_reset', [
            'user_id' => $user->id,
            ...$after,
            'organization_id' => null,
        ]);
    }

    /**
     * `Str::password(20)`'s symbol alphabet includes `<`, `>` and `\` — an
     * override seam, not an indirection for its own sake, so a test can pin
     * a value containing them without depending on a random draw.
     */
    protected function generatePassword(): string
    {
        return Str::password(20);
    }

    /**
     * The whole operator interface (design D5), printed AFTER the
     * transaction in handle() has committed — never before, and never from
     * inside the transaction closure.
     *
     * Ordering is deliberate:
     *   1. Identity line FIRST — if it names the wrong person, the operator
     *      can stop before reading a credential they should not have.
     *   2. Password line, via OUTPUT_RAW — see the comment above this call
     *      site in handle().
     *   3. The two consequence lines LAST, via $this->warn() — the last
     *      lines are the ones a scrolling terminal leaves on screen, and the
     *      labelled password is trivial to find above them.
     *
     * Wording is person-neutral: it reads correctly both when the operator
     * resets someone else and when they reset themselves.
     */
    private function report(User $user, string $password): void
    {
        $orgLabel = $user->organization_id === null
            ? 'none (platform superadmin)'
            : (string) $user->organization_id;

        $this->info("Password reset for: {$user->email} (user id={$user->id}, org id={$orgLabel})");

        // OUTPUT_RAW bypasses Symfony's OutputFormatter: `$this->line()`
        // routes through it, and `Str::password(20)`'s alphabet contains
        // `<`, `>` and `\` — all three of which the formatter assigns
        // tag/escape meaning to. A formatted credential can differ from the
        // stored one for the account that is supposed to unlock a
        // locked-out deployment; raw output guarantees the printed bytes
        // are the bytes that were hashed. The password appears in exactly
        // this one write call — never logged, never in an exception message.
        $this->output->writeln("New password: {$password}", OutputInterface::OUTPUT_RAW);

        $this->warn('All existing sessions for this user were revoked — every device must log in again.');
        $this->warn('Shown once and not recoverable. Store it now; change it from Profile → Password after logging in.');
    }
}
