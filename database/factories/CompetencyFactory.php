<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

/**
 * Factory for Competency (global model — no organization_id).
 *
 * Used in C4 tests for FK safeguard and competency-subset validation.
 *
 * `revision_id` defaults to the baseline — see `RoleFactory`'s identical
 * note (framework-catalogue-authoring PR3, DEFAULT removal).
 *
 * @extends Factory<Competency>
 */
class CompetencyFactory extends Factory
{
    protected $model = Competency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $attributes = [
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'type' => 'standard',
            // spatie/laravel-translatable — set JSON directly
            'name' => json_encode(['en' => $this->faker->word()]),
            'definition' => json_encode(['en' => $this->faker->sentence()]),
        ];

        // Guarded — see the identical note in `RoleFactory::definition()`.
        if (Schema::hasColumn('framework_competencies', 'revision_id')) {
            $attributes['revision_id'] = fn () => FrameworkCatalogRevision::where('is_baseline', true)->value('id');
        }

        return $attributes;
    }

    /**
     * Potential competency type (MTG/LAT).
     */
    public function potential(): static
    {
        return $this->state(fn (array $attrs) => [
            'type' => 'potential',
        ]);
    }
}
