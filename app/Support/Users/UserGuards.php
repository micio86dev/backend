<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Exceptions\Users\UserGuardException;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The last-admin / self-action invariants for every user-management write
 * (backoffice-missing-pages D4).
 *
 * ONE atomic entry point, `ensureAdminSurvivesThenMutate()`, used by BOTH the
 * role-change path and the deactivate path — a FormRequest rule would have
 * to duplicate this check twice today and would be forgotten the third time
 * a write path is added (a console command, a future bulk action).
 *
 * Count-then-write is wrapped in a transaction with `lockForUpdate()` on the
 * admin role-assignment rows: two admins demoting each other concurrently
 * would otherwise both observe "2 admins exist" and both commit, leaving the
 * organization with zero admins and no way back in.
 *
 * Self vs peer is resolved to a DIFFERENT machine-readable error code for the
 * SAME underlying "would this leave zero admins" check — `self_demotion` /
 * `self_deactivation` when the actor targets themselves, `last_admin`
 * otherwise. A caller who is not the last admin (2+ admins exist) may always
 * demote or deactivate themselves; the guard only fires when the count would
 * actually reach zero.
 */
final class UserGuards
{
    public function __construct(
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $mutate
     * @return TReturn
     *
     * @throws UserGuardException
     */
    public function ensureAdminSurvivesThenMutate(
        User $actor,
        User $target,
        bool $targetLosesAdminStatus,
        string $selfErrorCode,
        Closure $mutate,
    ): mixed {
        // Short-circuit: a write that does not remove admin status from the
        // target has nothing to do with this guard — never touches the lock
        // or the count, so an org with exactly one admin can still freely
        // manage its non-admin users.
        if (! $targetLosesAdminStatus) {
            return $mutate();
        }

        return DB::transaction(function () use ($actor, $target, $selfErrorCode, $mutate): mixed {
            $orgId = $this->resolver->getOrgId();

            $adminRoleId = SpatieRole::where('name', 'admin')
                ->where('guard_name', 'api')
                ->where('team_id', $orgId)
                ->value('id');

            // Row-lock the admin assignment rows for this org for the
            // duration of the transaction — a concurrent call attempting
            // the SAME lock blocks until this transaction commits or rolls
            // back, so it is impossible for two callers to both read the
            // pre-removal count. Postgres rejects `FOR UPDATE` combined with
            // an aggregate (`count(*)`) directly ("FOR UPDATE is not allowed
            // with aggregate functions") — lock the matching rows with a
            // plain SELECT, then count the resulting (small — never more
            // than a handful of admins) collection in PHP. Larastan's
            // "could have been retrieved as a query" suggestion does not
            // apply here: ->count() on the query builder would re-issue the
            // count as its own `SELECT count(*) ... FOR UPDATE`, which is
            // the exact SQL Postgres rejects.
            // @phpstan-ignore larastan.noUnnecessaryCollectionCall
            $adminCount = DB::table('model_has_roles')
                ->where('role_id', $adminRoleId)
                ->where('model_type', User::class)
                ->lockForUpdate()
                ->get()
                ->count();

            if ($adminCount <= 1) {
                $isSelf = $actor->is($target);

                throw new UserGuardException(
                    $isSelf ? $selfErrorCode : 'last_admin',
                    $isSelf
                        ? 'You are the last admin; this action would leave the organization with none.'
                        : 'The organization must retain at least one admin.',
                );
            }

            return $mutate();
        });
    }
}
