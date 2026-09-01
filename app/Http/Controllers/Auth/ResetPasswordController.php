<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\RefreshTokenStore;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/auth/reset-password` — consume a token and set a new password
 * (self-service-password-reset AD-2, AD-4).
 *
 * TOKEN HANDLING IS THE FRAMEWORK'S, NOT OURS
 * -------------------------------------------
 * `Password::broker()->reset()` already ships hashing-at-rest, single-use
 * consumption, expiry and per-user throttling, with exactly the semantics this
 * feature needs. A hand-rolled token table would reimplement four
 * security-critical behaviours to buy nothing, and would put a bespoke
 * credential primitive in the blast radius.
 *
 * ONE GENERIC FAILURE FOR EVERY REFUSAL
 * -------------------------------------
 * Invalid token, expired token, unknown email, deactivated user — all 422 with
 * the same body. A caller here must already hold a token, so the enumeration
 * exposure is far smaller than on the request leg, but distinguishing "this
 * account is disabled" from "this token is stale" still tells an attacker
 * something for free.
 *
 * The DEACTIVATED check runs BEFORE the broker, deliberately: the broker
 * deletes the token as part of a successful reset, so checking inside the
 * callback would spend a deactivated user's token to tell them no.
 *
 * WHAT A SUCCESSFUL RESET MUST DO — BOTH HALVES
 * ---------------------------------------------
 * 1. Stamp `password_changed_at` with `now()->startOfSecond()`, matching every
 *    existing writer. `RejectStaleCredentials` compares with a strict `<`
 *    against a second-precision `iat`; a sub-second value would let a token
 *    minted in the same second survive.
 * 2. Revoke EVERY refresh-token family the user holds. This is not belt and
 *    braces: `POST /api/auth/refresh` runs OUTSIDE `RejectStaleCredentials`
 *    (routes/api.php, D8 — an expired access token is exactly when refresh must
 *    still work), so `password_changed_at` is NEVER consulted there and a
 *    stolen refresh cookie would otherwise survive the reset and keep minting
 *    fresh access tokens. User-scoped, not family-scoped: a reset has no
 *    session and therefore no `fam` claim, and a user holds one family per
 *    login.
 *
 * Both happen inside one transaction with the password write, so a reset that
 * rolls back has not logged anyone out either.
 */
final class ResetPasswordController extends Controller
{
    public function __construct(private readonly RefreshTokenStore $refreshTokens) {}

    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');

        // Unscoped: there is no tenant context on an unauthenticated request,
        // `users.email` is globally unique, and platform superadmins
        // (organization_id IS NULL) must be reachable too.
        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->isDeactivated()) {
            // Mirrors `ResetUserPasswordCommand`'s "refuse, do not reactivate".
            $this->fail();
        }

        $status = Password::broker()->reset(
            [
                'token' => (string) $request->validated('token'),
                'email' => $email,
                'password' => (string) $request->validated('password'),
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ],
            function (User $resetUser, string $password): void {
                DB::transaction(function () use ($resetUser, $password): void {
                    // Plaintext in, hashed by the model's `hashed` cast.
                    $resetUser->password = $password;
                    $resetUser->password_changed_at = now()->startOfSecond();
                    $resetUser->save();

                    $this->refreshTokens->revokeAllForUser($resetUser->id);
                });
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // `$status` is a translation KEY, never the token. Not echoed to
            // the caller: `passwords.token` and `passwords.user` are
            // distinguishable strings, and returning them would undo the
            // single-generic-failure rule above.
            $this->fail();
        }

        $this->recordAudit($user);

        // A CODE, not a sentence. A response body is machine-facing (CLAUDE.md:
        // machine-readable values "are NOT user-facing and are returned
        // literally in every locale"), and only the UI knows the reader's
        // locale. The English prose that used to be here was rendered verbatim
        // by the backoffice, so an Italian operator on an Italian page read
        // English.
        return response()->json(['message' => 'password_reset']);
    }

    /**
     * The single refusal shape. `ValidationException` so it renders as the
     * repo's ordinary 422 envelope rather than a bespoke one.
     */
    private function fail(): never
    {
        // ONE code for all four causes, which is the whole point of this method
        // — a distinguishable message would rebuild the enumeration oracle the
        // endpoint refuses to be. A code rather than prose for the reason above
        // `password_reset`: this string is the only thing that reaches the
        // operator, so it is also the only one that has to be translatable.
        throw ValidationException::withMessages([
            'token' => ['reset_link_invalid'],
        ]);
    }

    /**
     * Same action, same table, same superadmin fallback as
     * `ResetUserPasswordCommand::recordAudit()` — the audit trail must not
     * acquire a hole shaped like "the common case" now that the common case is
     * self-service.
     *
     * `audit_logs.organization_id` is a NOT NULL foreign key because the table
     * is tenant-scoped by design, and a platform superadmin has no tenant for
     * the row to belong to. Faking a real org's id would misattribute the reset
     * into that org's trail; widening the column would weaken an invariant
     * every other row depends on. The log fallback is deliberate and visible,
     * not a silent gap.
     */
    private function recordAudit(User $user): void
    {
        $after = ['email' => $user->email, 'channel' => 'self_service'];

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
}
