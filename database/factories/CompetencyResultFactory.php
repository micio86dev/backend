<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for CompetencyResult (C9 — Scoring Engine).
 *
 * Defaults to a valid scored competency with COL-style data.
 *
 * NOTE: organization_id is NOT fillable — stamped by TenantScoped.creating.
 *
 * Named states:
 *   ->valid()      — scored and valid (reliability ≥ threshold).
 *   ->unscorable() — unscorable with role_no_bars reason.
 *   ->anchorMissing() — anchor_translation_missing unscorable reason.
 *   ->parseError() — llm_parse_error unscorable reason.
 *
 * @extends Factory<CompetencyResult>
 */
class CompetencyResultFactory extends Factory
{
    protected $model = CompetencyResult::class;

    /**
     * Define the model's default state (valid scored competency).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'evaluation_id' => Evaluation::factory(),
            'competency_code' => $this->faker->randomElement(['COL', 'PRS', 'STG', 'SLF', 'COM']),
            'score' => $this->faker->randomFloat(2, 1.0, 5.0),
            'reliability' => $this->faker->randomFloat(4, 0.5, 1.0),
            'valid' => true,
            'unscorable_reason' => null,
        ];
    }

    /**
     * Valid scored competency state.
     */
    public function valid(): static
    {
        return $this->state(fn (array $attrs) => [
            'score' => 3.67,
            'reliability' => 1.0000,
            'valid' => true,
            'unscorable_reason' => null,
        ]);
    }

    /**
     * Unscorable — missing BARS catalog for role.
     */
    public function unscorable(): static
    {
        return $this->state(fn (array $attrs) => [
            'score' => null,
            'reliability' => 0.0000,
            'valid' => false,
            'unscorable_reason' => 'role_no_bars',
        ]);
    }

    /**
     * Unscorable — missing anchor translation.
     */
    public function anchorMissing(): static
    {
        return $this->state(fn (array $attrs) => [
            'score' => null,
            'reliability' => 0.0000,
            'valid' => false,
            'unscorable_reason' => 'anchor_translation_missing',
        ]);
    }

    /**
     * Unscorable — persistent LLM parse error.
     */
    public function parseError(): static
    {
        return $this->state(fn (array $attrs) => [
            'score' => null,
            'reliability' => 0.0000,
            'valid' => false,
            'unscorable_reason' => 'llm_parse_error',
        ]);
    }
}
