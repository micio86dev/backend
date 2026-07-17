<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Competency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Competency (global model — no organization_id).
 *
 * Used in C4 tests for FK safeguard and competency-subset validation.
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
        return [
            'code'       => strtoupper($this->faker->unique()->lexify('???')),
            'type'       => 'standard',
            // spatie/laravel-translatable — set JSON directly
            'name'       => json_encode(['en' => $this->faker->word()]),
            'definition' => json_encode(['en' => $this->faker->sentence()]),
        ];
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
