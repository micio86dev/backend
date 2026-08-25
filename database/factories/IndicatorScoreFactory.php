<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompetencyResult;
use App\Models\IndicatorScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for IndicatorScore (C9 — Scoring Engine).
 *
 * Defaults to a scored indicator with score=5 (high-anchor).
 *
 * NOTE: organization_id is NOT fillable — stamped by TenantScoped.creating.
 *
 * @extends Factory<IndicatorScore>
 */
class IndicatorScoreFactory extends Factory
{
    protected $model = IndicatorScore::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competency_result_id' => CompetencyResult::factory(),
            'position' => 0,
            'indicator_text' => $this->faker->sentence(8),
            'score' => 5,
            'explanation' => $this->faker->paragraph(),
            'excerpts' => [$this->faker->sentence(10)],
        ];
    }

    /**
     * Unassessable indicator state (score = -1, empty excerpts).
     *
     * Defaults `unassessable_reason` to `model_declared` (C13,
     * scoring-failure-containment D7/D1): the `indicator_scores_unassessable_
     * reason_check` equivalence CHECK requires `unassessable_reason IS NOT
     * NULL` whenever `score = -1`, and `model_declared` is the correct
     * default for a bare `->unassessable()` call — pass `unassessableReason:`
     * to override for a validation-failure state (`score_illegal`,
     * `excerpt_unverifiable`).
     */
    public function unassessable(?string $unassessableReason = 'model_declared'): static
    {
        return $this->state(fn (array $attrs) => [
            'score' => -1,
            'excerpts' => [],
            'unassessable_reason' => $unassessableReason,
        ]);
    }
}
