<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstrap artisan command to mint the first platform superadmin.
 *
 * Creates a User with:
 *   - organization_id = NULL  (no tenant context)
 *   - is_superadmin = true    (explicit bypass discriminator)
 *
 * SECURITY CONSTRAINTS:
 * - This command is NEVER called from automated seeders (DatabaseSeeder, etc.).
 * - It is run ONCE during initial platform setup by a human operator.
 * - The is_superadmin column is set directly (not via mass-assignment) because
 *   it is intentionally excluded from User::$fillable.
 */
final class CreateSuperadmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-superadmin';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bootstrap: create the first platform superadmin (org=null, is_superadmin=true). Run once. NEVER call from automated seeders.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email    = $this->ask('Email');
        $name     = $this->ask('Name');
        $password = $this->ask('Password');

        if (empty($email) || empty($name) || empty($password)) {
            $this->error('Email, name, and password are all required.');

            return self::FAILURE;
        }

        // Mass-assignment is intentionally bypassed for these privileged columns.
        $user                  = new User();
        $user->name            = $name;
        $user->email           = $email;
        $user->password        = Hash::make($password);
        $user->organization_id = null;
        $user->is_superadmin   = true;
        $user->save();

        $this->info("Platform superadmin created: {$email} (id={$user->id})");

        return self::SUCCESS;
    }
}
