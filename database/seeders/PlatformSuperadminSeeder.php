<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The platform superadmin, for environments where nobody can run an
 * interactive console command.
 *
 * `app:create-superadmin` prompts for three answers, which is fine on a laptop
 * and impossible in a Railway deploy hook. This is the same account, created
 * from configuration.
 *
 * THE PASSWORD COMES FROM THE ENVIRONMENT AND IS NEVER COMMITTED. A seeder
 * carrying a literal password would put platform-wide production access in
 * every clone of the repository, every CI log that echoes a diff, and every
 * fork — and git history does not forget. `SUPERADMIN_PASSWORD` is set on the
 * deployment and read here; with nothing set this seeder REFUSES to run rather
 * than inventing a default, because a default is exactly the credential an
 * attacker would try first.
 *
 * IDEMPOTENT, and deliberately non-destructive: if the account already exists
 * its password is left ALONE. A seeder that reset it on every deploy would
 * silently undo a rotation the moment someone redeployed, and the operator
 * would have no way to tell why their new password stopped working.
 */
class PlatformSuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('superadmin.email', '');
        $password = (string) config('superadmin.password', '');

        if ($email === '') {
            $this->command?->info('SUPERADMIN_EMAIL is not set — skipping. This seeder is opt-in.');

            return;
        }

        $existing = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($existing !== null) {
            // Present already. Repair the two fields that DEFINE a superadmin
            // rather than assume they are right: an account demoted by hand,
            // or created before `is_superadmin` existed, would otherwise stay
            // broken through every future deploy.
            $existing->forceFill([
                'is_superadmin' => true,
                'organization_id' => null,
                'deactivated_at' => null,
            ])->save();

            $this->command?->info("Platform superadmin already present: {$email} (id={$existing->id}).");

            return;
        }

        if ($password === '') {
            throw new RuntimeException(
                'SUPERADMIN_PASSWORD must be set to create the platform superadmin. '
                .'Refusing to invent a default: a committed or guessable password on this account '
                .'is platform-wide access to every tenant.'
            );
        }

        $user = new User;
        $user->name = (string) config('superadmin.name', 'Platform Superadmin');
        $user->email = $email;
        $user->password = Hash::make($password);
        // NULL organization plus the flag is what TenantContext requires to
        // grant bypass; either one alone fails closed with a 403.
        $user->organization_id = null;
        $user->is_superadmin = true;
        $user->save();

        $this->command?->info("Platform superadmin created: {$email} (id={$user->id}).");
    }
}
