<?php

declare(strict_types=1);

/**
 * `php artisan db:seed` on a fresh database.
 *
 * `DatabaseSeeder` once used `WithoutModelEvents`, which mutes every model
 * event for the whole seeding run — including the `creating` listeners that
 * fill NOT NULL columns (`organizations.public_id`, `revision_id`). The run
 * aborted with SQLSTATE[23502] on a fresh database.
 */

use App\Models\Competency;
use App\Models\Organization;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;

test('the full database seeder completes on a fresh database and fills model-event columns', function (): void {
    $this->seed(DatabaseSeeder::class);

    $org = Organization::where('slug', 'dev-org')->firstOrFail();

    expect($org->public_id)->toBeString()->toHaveLength(26);
    expect(Role::query()->whereNull('revision_id')->exists())->toBeFalse();
    expect(Competency::query()->whereNull('revision_id')->exists())->toBeFalse();
});
