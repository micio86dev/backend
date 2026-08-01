<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Support\Collection;
// Spatie's AUTHORIZATION role (admin/operator/viewer) — deliberately NOT
// App\Models\Role, which is the BEAI ORGANIZATIONAL role from C3
// (ICO/FLL/MLL/BUL/SRX), a domain concept with no bearing on who receives an
// email. CLAUDE.md flags this collision by name; importing the wrong one here
// would query an entirely unrelated table and quietly resolve zero recipients.
use Spatie\Permission\Models\Role as AuthorizationRole;

/**
 * The ONLY sanctioned path to a notification recipient set (C12, D2).
 *
 * Modelled on App\Support\Admin\AdminParticipantReader (C11 D1): the tenant
 * filter is a mandatory constructor-of-the-query argument rather than a
 * convention someone has to remember.
 *
 * There is no zero-argument overload, no default, and no `?int`. Omitting the
 * organization is a PHPStan L8 TYPE ERROR, not a runtime cross-tenant
 * disclosure. That distinction is the whole design: a documented convention in
 * the calling job is exactly the mechanism that produces this hazard, and an
 * arch test alone is a lint after the fact which cannot express WHICH filter is
 * correct. The arch test in tests/Arch/Notifications is kept as a backstop, not
 * as the mechanism.
 */
final class OperatorRecipientResolver
{
    /**
     * Every user in $organizationId holding one of the configured recipient
     * roles.
     *
     * @return Collection<int, User>
     */
    public function forOrganization(int $organizationId): Collection
    {
        /** @var list<string> $roleNames */
        $roleNames = config('notifications.recipients.roles', []);
        /** @var string $guard */
        $guard = config('notifications.recipients.guard');

        if ($roleNames === []) {
            return collect();
        }

        $roles = $this->resolveRoles($roleNames, $guard, $organizationId);

        // No role row for this organization means nobody holds it. That is an
        // empty recipient set, not an error — see resolveRoles().
        if ($roles->isEmpty()) {
            return collect();
        }

        /** @var string $pivotTable */
        $pivotTable = config('permission.table_names.model_has_roles');
        /** @var string $morphKey */
        $morphKey = config('permission.column_names.model_morph_key');

        return User::query()
            // (1) The load-bearing filter. User extends Authenticatable, NOT
            // TenantModel, so there is no global scope to fall back on.
            //
            // This also excludes platform superadmins by construction rather
            // than by a second condition: users.organization_id is nullable,
            // and `where(col, value)` never matches NULL.
            ->where('organization_id', $organizationId)
            // (2) The role pivot, queried DIRECTLY rather than through Spatie's
            // `->role()` scope. That scope filters by the PermissionRegistrar's
            // ambient team id, which this class does not set and must not
            // depend on: the whole point of a mandatory $organizationId
            // argument is that the answer cannot change based on state
            // somebody else left behind. Proven by test — resolving for org A
            // after touching org B returned an empty set through `->role()`.
            //
            // Safe because $roles was already filtered to team_id =
            // $organizationId, so these role ids belong to this org by
            // construction. Both filters, always, independently.
            ->whereIn('id', function ($query) use ($pivotTable, $morphKey, $roles): void {
                $query->select($morphKey)
                    ->from($pivotTable)
                    ->whereIn('role_id', $roles->pluck('id')->all())
                    ->where('model_type', (new User)->getMorphClass());
            })
            ->get();
    }

    /**
     * Resolve role NAMES to Role MODELS, defensively.
     *
     * This exists because of a verified Spatie landmine: passing names to
     * `->role()` makes scopeRole resolve each one via Role::findByName, which
     * THROWS RoleDoesNotExist when the row is absent — it does not return an
     * empty set. Role rows are per-organization (the seeder sets the team id
     * first), so an organization that never had an `operator` role row would
     * make the recipient query throw INSIDE the alerting job: retry, retry,
     * dead job, and the operator never learns their integration is broken.
     *
     * Looking the rows up here turns "this role does not exist for this org"
     * into "this role contributes no recipients", which is the correct
     * behaviour for an alerting path. scopeRole accepts Role instances and does
     * not re-resolve them.
     *
     * @param  list<string>  $roleNames
     * @return Collection<int, AuthorizationRole>
     */
    private function resolveRoles(array $roleNames, string $guard, int $organizationId): Collection
    {
        /** @var Collection<int, AuthorizationRole> $roles */
        $roles = AuthorizationRole::query()
            ->whereIn('name', $roleNames)
            ->where('guard_name', $guard)
            ->where('team_id', $organizationId)
            ->get();

        return $roles;
    }
}
