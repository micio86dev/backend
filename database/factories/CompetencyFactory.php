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
 * `revision_id` is deliberately NOT defaulted here — see `RoleFactory`'s
 * identical note (framework-catalogue-authoring PR3b, H10):
 * `Competency::booted()`'s own `creating` listener is the single mechanism
 * that defaults it to the baseline, for every creation path.
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
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'type' => 'standard',
            // Bare arrays, not json_encode() (gga review finding: this and
            // `RoleFactory` modelled the SAME `HasTranslations` attribute
            // shape two different ways) — `HasTranslations::setAttribute()`
            // detects an array and encodes it itself; a caller pre-encoding
            // does not fail, it just bypasses that trait's own path for no
            // benefit.
            'name' => ['en' => $this->faker->word()],
            'definition' => ['en' => $this->faker->sentence()],
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
