<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seed Spatie authorization roles (admin, operator, viewer) per organization.
 *
 * IMPORTANT invariants (C2 — D2):
 * - Roles are ALWAYS org-scoped (team_id = organization_id). Spatie teams mode
 *   must be enabled in config/permission.php (teams = true).
 * - NO global `superadmin` Spatie role is seeded. Platform superadmin identity
 *   is determined exclusively by the `is_superadmin` boolean on the users table.
 * - NO BEAI organizational roles (ICO, FLL, MLL, BUL, SRX) are seeded here.
 *   Those are domain/framework concepts owned by C3.
 *
 * This seeder is for development/staging. It creates one dev org if none exists
 * and seeds the three auth roles for it.
 *
 * NEVER call app:create-superadmin from this seeder.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Get or create a dev organization for local seeding.
        $org = Organization::firstOrCreate(
            ['slug' => 'dev-org'],
            ['name' => 'Dev Organization'],
        );

        // Set the Spatie team context to this org.
        // All roles created here are scoped to this team_id.
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

        // Seed the three authorization roles org-scoped.
        // No superadmin role. No BEAI framework roles (ICO/FLL/MLL/BUL/SRX).
        foreach (['admin', 'operator', 'viewer'] as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'api'],
            );
        }

        $this->command->info("Seeded roles [admin, operator, viewer] for org: {$org->name} (id={$org->id})");
    }
}
