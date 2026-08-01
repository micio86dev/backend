<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiRequest;
use App\Models\Evaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for AiRequest (C9 — Scoring Engine).
 *
 * Append-only audit log rows for LLM calls during scoring.
 *
 * NOTE: organization_id is NOT fillable — stamped by TenantScoped.creating.
 * NOTE: No updated_at — AiRequest is append-only ($timestamps = false).
 *
 * @extends Factory<AiRequest>
 */
class AiRequestFactory extends Factory
{
    protected $model = AiRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'evaluation_id' => Evaluation::factory(),
            'provider' => 'anthropic',
            'estimated_cost_usd' => 0.001234,
            'success' => true,
            'failure_reason' => null,
            'competency_code' => $this->faker->randomElement(['COL', 'PRS', 'STG', 'SLF', 'COM']),
            'model' => 'fake-llm-provider-v1',
            'prompt_version' => '1.0.0',
            'input_tokens' => $this->faker->numberBetween(500, 2000),
            'output_tokens' => $this->faker->numberBetween(100, 500),
            'finish_reason' => 'stop',
            'latency_ms' => $this->faker->numberBetween(200, 3000),
        ];
    }
}
