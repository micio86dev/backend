<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

/**
 * Factory for Role (C3 Framework Catalog global model).
 *
 * Used in scoring tests (PR2) to create BARS indicator relationships.
 *
 * `revision_id` defaults to the baseline (framework-catalogue-authoring
 * PR3): `revision_id` no longer carries a DB-level DEFAULT
 * (2026_09_15_201435_drop_catalogue_revision_defaults) now that every
 * writer must name a revision explicitly — this factory is that explicit
 * name for the dozens of pre-existing call sites across the suite that
 * create a `Role` with no opinion on which revision it belongs to. A caller
 * that DOES care overrides it exactly as before
 * (`Role::factory()->create(['revision_id' => $x])`).
 *
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper($this->faker->unique()->lexify('???'));

        $attributes = [
            'code' => $code,
            'name' => ['en' => $this->faker->words(3, true)],
            'responsibilities' => ['en' => $this->faker->sentence()],
        ];

        // Guarded, not a bare closure: `BaselineRevisionMigrationTest` calls
        // this factory against the deliberately-rolled-back PRE-revision
        // schema, where neither the column nor `framework_catalog_revisions`
        // exists yet — querying for a value to put in a column that is not
        // there is itself the failure, not merely a wrong value.
        if (Schema::hasColumn('framework_roles', 'revision_id')) {
            $attributes['revision_id'] = fn () => FrameworkCatalogRevision::where('is_baseline', true)->value('id');
        }

        return $attributes;
    }
}
