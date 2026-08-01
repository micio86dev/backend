<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // NO factories here, and that is a hard constraint rather than a style
        // choice. Factories call fake(), which comes from fakerphp/faker — a
        // require-dev package. The Docker image installs with --no-dev, so a
        // single User::factory() call made `php artisan db:seed` fail inside
        // every container with "Call to undefined function fake()".
        //
        // The failure took the framework catalog down with it, because this
        // method aborted before reaching it — leaving a database with no
        // competencies, no roles and no indicators, which is a database no
        // project can be created against. A seeder that cannot run where it is
        // deployed is not a seeder.

        // Org-scoped authorization roles (admin/operator/viewer) plus the
        // `dev-org` organization they hang off. Must precede anything that
        // assigns a role.
        $this->call(RolesAndPermissionsSeeder::class);

        // C3 Framework Catalog — idempotent global catalog seed
        $this->call(FrameworkCatalogSeeder::class);
    }
}
