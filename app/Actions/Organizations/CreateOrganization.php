<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Support\Superadmin\PlatformAuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create an organization and the three authorization roles every tenant needs.
 *
 * Shared by `POST /api/admin/organizations` and `beai:provision-organization`
 * so the two entry points cannot drift on what "a usable organization" means:
 * an organization without its roles cannot have a user assigned to it.
 */
final class CreateOrganization
{
    /**
     * Spatie AUTHORIZATION roles — not the BEAI organizational roles
     * (ICO/FLL/MLL/BUL/SRX), which belong to the framework catalogue.
     *
     * @var list<string>
     */
    public const ROLES = ['admin', 'operator', 'viewer'];

    public function __construct(
        private readonly PlatformAuditWriter $auditWriter,
        private readonly EnsureDefaultFrameworkVersion $ensureDefaultFrameworkVersion,
    ) {}

    /**
     * Runs its own transaction; safe to call from inside another one.
     */
    public function create(string $name, string $slug, ?User $actor = null): Organization
    {
        return DB::transaction(function () use ($name, $slug, $actor): Organization {
            $organization = Organization::create(['name' => $name, 'slug' => $slug]);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            foreach (self::ROLES as $roleName) {
                // team_id EXPLICIT: setPermissionsTeamId() does not reach
                // firstOrCreate(), which builds the row from its attributes
                // alone (the mistake RolesAndPermissionsSeeder once shipped).
                Role::firstOrCreate(
                    ['name' => $roleName, 'guard_name' => 'api', 'team_id' => $organization->id],
                );
            }

            // Without a version the organization cannot create a project.
            // `false` here means no catalogue revision is published yet: the
            // organization is still created, and `beai:ensure-framework-versions`
            // (run by every deploy) gives it a version once one exists.
            if (! $this->ensureDefaultFrameworkVersion->ensure($organization)) {
                Log::warning('Organization created without a default framework version: no published catalogue revision.', [
                    'organization_id' => $organization->id,
                ]);
            }

            if ($actor !== null) {
                $this->auditWriter->record(
                    actorId: $actor->id,
                    action: 'organization.created',
                    subjectType: 'Organization',
                    subjectId: $organization->id,
                    before: null,
                    after: ['name' => $name, 'slug' => $slug],
                );
            }

            return $organization;
        });
    }
}
