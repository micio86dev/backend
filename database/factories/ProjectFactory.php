<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for Project (C4 Project Configuration).
 *
 * Defaults to a 'standard' type draft project with role_code 'ICO'.
 * Named states: standard(), potential().
 *
 * NOTE: organization_id is NOT fillable — it is stamped by TenantScoped.creating
 * from the active TenantResolver. In tests, set the resolver before creating projects.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'framework_version_id' => FrameworkVersion::factory(),
            'slug'                 => Str::slug($this->faker->unique()->words(3, true)),
            'name'                 => $this->faker->sentence(4),
            'assessment_type'      => 'standard',
            'role_code'            => 'ICO',
            'language'             => 'en',
            'status'               => 'draft',
            'webhook_url'          => null,
            'webhook_secret'       => null,
        ];
    }

    /**
     * Standard assessment type (ICO role, standard competencies).
     */
    public function standard(): static
    {
        return $this->state(fn (array $attrs) => [
            'assessment_type' => 'standard',
            'role_code'       => 'ICO',
        ]);
    }

    /**
     * Potential assessment type (no role_code).
     */
    public function potential(): static
    {
        return $this->state(fn (array $attrs) => [
            'assessment_type' => 'potential',
            'role_code'       => null,
        ]);
    }
}
