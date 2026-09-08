<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Exceptions\Users\UserGuardException;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The last-superadmin invariant (platform-user-management D3).
 *
 * {@see UserGuards} refuses to leave an organization with zero admins. The
 * platform has the same failure mode and a worse blast radius: deactivate the
 * last ACTIVE superadmin and nobody can administer BEAI again — no clients
 * console, no platform settings, and no way to create a replacement short of
 * shell access to the production container, because `beai:create-superadmin`
 * is the only other route in.
 *
 * Deactivation is the only guarded verb here. There is no demotion: without a
 * role there is nothing to demote to, and deletion is not offered at all for
 * the reason the org surface already records — a DELETE that does not delete
 * would lie about what it does.
 */
final class PlatformUserGuards
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $mutate
     * @return TReturn
     *
     * @throws UserGuardException
     */
    public function ensureSuperadminSurvivesThenMutate(
        User $actor,
        User $target,
        Closure $mutate,
    ): mixed {
        // Short-circuit, mirroring UserGuards' own `! $targetLosesAdminStatus`
        // branch: deactivating someone who is ALREADY deactivated removes no
        // survivor, so this guard has nothing to say about it. Without this it
        // refused a write that could not break its invariant — one active
        // superadmin plus one retired peer, and reactivating-then-retiring the
        // peer was impossible — and took a FOR UPDATE lock to do it.
        if ($target->deactivated_at !== null) {
            return $mutate();
        }

        return DB::transaction(function () use ($actor, $target, $mutate): mixed {
            // Count-then-write inside ONE transaction, under a row lock. Two
            // superadmins deactivating each other concurrently would otherwise
            // both observe "2 remain" and both commit, leaving the platform
            // with none and nobody able to undo it.
            //
            // Locking `users` rows directly is right here, unlike UserGuards'
            // careful avoidance of a locked join: this transaction's entire
            // business IS the users table, and the set is a handful of rows.
            //
            // ACTIVE only. Holding the identity is not the same as being able
            // to use it: a deactivated superadmin is refused by TenantContext
            // on every request including login, so counting one as a survivor
            // is not counting administrators — the exact defect UserGuards
            // records having shipped once already.
            // Counted in PHP over the locked ids, never as `->count()` on the
            // query: Postgres rejects `FOR UPDATE` combined with an aggregate
            // ("FOR UPDATE is not allowed with aggregate functions"), so
            // asking the builder to count would re-issue the lock as
            // `SELECT count(*) ... FOR UPDATE` and fail. The set is a handful
            // of rows and is already loaded. Same constraint UserGuards works
            // around, reached by a different route.
            $activeIds = User::query()
                ->whereNull('organization_id')
                ->where('is_superadmin', true)
                ->whereNull('deactivated_at')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $activeCount = count($activeIds);

            if ($activeCount <= 1) {
                $isSelf = $actor->is($target);

                throw new UserGuardException(
                    $isSelf ? 'self_deactivation' : 'last_superadmin',
                    $isSelf
                        ? 'You are the last active superadmin; this action would leave the platform with none.'
                        : 'The platform must retain at least one active superadmin.',
                );
            }

            return $mutate();
        });
    }
}
