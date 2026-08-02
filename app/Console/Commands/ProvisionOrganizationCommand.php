<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Provision one organization and its first administrator.
 *
 * This is the bootstrap that makes a fresh deployment usable: a migrated
 * database with no organization has no admin, and therefore no account anyone
 * can log in with.
 *
 * DISTINCT FROM app:create-superadmin, which mints a PLATFORM superadmin
 * (organization_id = NULL, is_superadmin = true) and creates no organization at
 * all. Platform superadmin and organization admin are different identities and
 * C2 forbids conflating them.
 *
 * Every input is an option rather than a prompt, because the place this command
 * is most needed — a production container — has no TTY, and under
 * --no-interaction an ask() returns null.
 */
final class ProvisionOrganizationCommand extends Command
{
    /**
     * The three authorization roles every organization gets. These are Spatie
     * AUTHORIZATION roles — not the BEAI organizational roles (ICO/FLL/MLL/
     * BUL/SRX), which are a domain concept owned by the framework catalogue.
     *
     * @var list<string>
     */
    private const ROLES = ['admin', 'operator', 'viewer'];

    protected $signature = 'beai:provision-organization
        {--name= : Organization display name (required)}
        {--slug= : URL-safe identifier; derived from the name when omitted}
        {--admin-email= : Email of the first administrator (required)}
        {--admin-name= : Display name of the administrator; defaults to the organization name}
        {--admin-password= : Administrator password; generated and printed when omitted}
        {--locale=it : Administrator notification locale}';

    protected $description = 'Create an organization, its authorization roles, and its first administrator.';

    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        $adminEmail = trim((string) $this->option('admin-email'));
        $slug = trim((string) $this->option('slug')) ?: Str::slug($name);
        $adminName = trim((string) $this->option('admin-name')) ?: $name;
        $locale = trim((string) $this->option('locale')) ?: 'it';

        $suppliedPassword = (string) $this->option('admin-password');
        $passwordWasGenerated = $suppliedPassword === '';
        $password = $passwordWasGenerated ? Str::password(20) : $suppliedPassword;

        if ($error = $this->validateInput($name, $adminEmail, $slug)) {
            $this->error($error);

            return self::FAILURE;
        }

        try {
            $organization = DB::transaction(
                fn (): Organization => $this->provision($name, $slug, $adminName, $adminEmail, $password, $locale)
            );
        } catch (Throwable $e) {
            // The transaction has already rolled back, so nothing partial
            // survives. What the operator needs is the reason, not a trace.
            $this->error("Provisioning failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->reportSuccess($organization, $adminEmail, $password, $passwordWasGenerated);

        return self::SUCCESS;
    }

    /**
     * Returns an operator-readable reason to stop, or null to proceed.
     *
     * These checks are advisory: the real guarantees are the unique indexes on
     * organizations.slug and users.email. They exist to turn a QueryException
     * stack trace into a sentence someone can act on.
     */
    private function validateInput(string $name, string $adminEmail, string $slug): ?string
    {
        if ($name === '') {
            return 'The --name option is required.';
        }

        if ($adminEmail === '') {
            return 'The --admin-email option is required.';
        }

        if (Validator::make(['email' => $adminEmail], ['email' => 'email:rfc'])->fails()) {
            return "Not a valid email address: {$adminEmail}";
        }

        if ($slug === '') {
            return 'Could not derive a slug from the name; pass --slug explicitly.';
        }

        if (Organization::where('slug', $slug)->exists()) {
            // Refused rather than adopted: adopting would make --name silently
            // do nothing and leave the operator believing they configured an
            // organization they did not.
            return "An organization with slug [{$slug}] already exists.";
        }

        if (User::where('email', $adminEmail)->exists()) {
            return "A user with email [{$adminEmail}] already exists.";
        }

        return null;
    }

    /**
     * Write the organization, its roles and its admin. Runs inside a
     * transaction: a tenant with no admin is invisible from the backoffice and
     * removable only by hand, in production.
     */
    private function provision(
        string $name,
        string $slug,
        string $adminName,
        string $adminEmail,
        string $password,
        string $locale,
    ): Organization {
        $organization = Organization::create(['name' => $name, 'slug' => $slug]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (self::ROLES as $roleName) {
            // team_id passed EXPLICITLY. setPermissionsTeamId() governs Spatie's
            // own Role::create() and the runtime checks — it does NOT reach
            // Eloquent's firstOrCreate(), which builds the row from the
            // attributes it is handed and nothing else. This exact mistake
            // shipped once in RolesAndPermissionsSeeder: roles written with
            // team_id = NULL, invisible to every teams-mode hasRole() check.
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'api', 'team_id' => $organization->id],
            );
        }

        // Set for assignRole() below, which DOES resolve the role through the
        // registrar. Both this and the explicit team_id above are required;
        // neither is sufficient on its own.
        $registrar->setPermissionsTeamId($organization->id);

        $admin = new User;
        $admin->name = $adminName;
        $admin->email = $adminEmail;
        // Assigned as plaintext: the model's `hashed` cast hashes it, and
        // no-ops on an already-hashed value.
        $admin->password = $password;
        $admin->locale = $locale;
        // organization_id and is_superadmin are excluded from $fillable by C2
        // security invariant, so neither goes through mass assignment.
        //
        // The tenant link is made through the relation rather than by writing
        // the key by hand: it takes the Organization itself, so there is no way
        // to associate an id that belongs to nothing.
        $admin->organization()->associate($organization);
        // Written explicitly rather than left to the column default: this
        // command's whole purpose is minting a privileged account, and "the
        // default protects us" is an assumption worth one line to stop making.
        $admin->is_superadmin = false;
        $admin->save();

        $admin->assignRole('admin');

        return $organization;
    }

    private function reportSuccess(
        Organization $organization,
        string $adminEmail,
        string $password,
        bool $passwordWasGenerated,
    ): void {
        $this->info("Organization provisioned: {$organization->name} (id={$organization->id}, slug={$organization->slug})");
        $this->line('Roles created: '.implode(', ', self::ROLES).' (scoped to this organization)');
        $this->line("Administrator: {$adminEmail}");

        if ($passwordWasGenerated) {
            // Shown once and stored only as a hash: if this is not printed the
            // account is unusable. A supplied password is never echoed — the
            // operator has it, and printing only widens where it lands.
            $this->line("Password: {$password}");
            $this->warn('This password is shown once and cannot be recovered. Store it now.');
        }
    }
}
