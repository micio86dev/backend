<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FrameworkVersion;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for FrameworkVersion (C4).
 *
 * NOTE on is_locked: is_locked is NOT in $fillable (C4 design decision).
 * - Use factory()->create() for unlocked FV (default).
 * - Use factory()->locked()->create() for a locked FV (Pattern B).
 * - NEVER use factory()->create(['is_locked' => true]) — silently produces an UNLOCKED FV.
 *
 * @extends Factory<FrameworkVersion>
 */
class FrameworkVersionFactory extends Factory
{
    protected $model = FrameworkVersion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'version' => 'v' . $this->faker->unique()->numerify('#.#.#'),
            'label' => $this->faker->optional()->sentence(3),
            // is_locked defaults to false in the DB — do NOT set it here.
        ];
    }

    /**
     * Named state: locked FrameworkVersion.
     *
     * Uses afterCreating + forceFill to bypass the $fillable restriction.
     * This is the only correct pattern for locking an FV in tests (Pattern B).
     * NEVER use state(['is_locked' => true]) — that triggers mass-assignment which
     * silently ignores non-fillable keys, producing a false-green test.
     */
    public function locked(): static
    {
        return $this->afterCreating(function (FrameworkVersion $fv): void {
            tap($fv->forceFill(['is_locked' => true]))->save();
        });
    }
}
