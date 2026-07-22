<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Evaluation (C9 — Scoring Engine).
 *
 * Defaults to a 'processing' status Evaluation with placeholder version strings.
 *
 * NOTE: organization_id is NOT fillable — stamped by TenantScoped.creating from
 * the active TenantResolver. In tests, set the resolver before creating Evaluations.
 *
 * Named states:
 *   ->processing() — default state (Evaluation just started).
 *   ->completed()  — terminal completed state (gate passed).
 *   ->pending()    — terminal pending state (gate not passed).
 *
 * @extends Factory<Evaluation>
 */
class EvaluationFactory extends Factory
{
    protected $model = Evaluation::class;

    /**
     * Define the model's default state (processing).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'status' => EvaluationStatus::Processing->value,
            'framework_version_id' => FrameworkVersion::factory(),
            'model_version' => 'fake-llm-provider-v1',
            'prompt_version' => '1.0.0',
            'evaluated_at' => null,
            'retry_attempt' => false,
        ];
    }

    /**
     * Processing status state.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => EvaluationStatus::Processing->value,
            'evaluated_at' => null,
        ]);
    }

    /**
     * Completed terminal state (90% gate passed).
     */
    public function completed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => EvaluationStatus::Completed->value,
            'evaluated_at' => now(),
        ]);
    }

    /**
     * Pending terminal state (90% gate not passed; still delivered).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => EvaluationStatus::Pending->value,
            'evaluated_at' => now(),
        ]);
    }
}
