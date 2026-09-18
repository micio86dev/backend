<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Audit\AuditRunStatus;
use App\Models\Evaluation;
use App\Models\IndicatorScoreAuditRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for IndicatorScoreAuditRun (scoring-audit-jev P2).
 *
 * Defaults to a fully successful run: every counter reconciles the C-D
 * four-term coverage identity, and `status = completed` with no
 * `failure_reason`.
 *
 * NOTE: organization_id is NOT fillable — stamped by TenantScoped.creating.
 * NOTE: No updated_at — append-only, same shape as AiRequestFactory.
 *
 * @extends Factory<IndicatorScoreAuditRun>
 */
class IndicatorScoreAuditRunFactory extends Factory
{
    protected $model = IndicatorScoreAuditRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $judged = $this->faker->numberBetween(1, 15);
        $skipped = $this->faker->numberBetween(0, 3);

        return [
            'evaluation_id' => Evaluation::factory(),
            'requested_by_user_id' => null,
            'status' => AuditRunStatus::Completed->value,
            'failure_reason' => null,
            'indicators_total' => $judged + $skipped,
            'indicators_judged' => $judged,
            'indicators_skipped' => $skipped,
            'indicators_unavailable' => 0,
            'indicators_malformed' => 0,
            'input_tokens' => $this->faker->numberBetween(500, 5000),
            'output_tokens' => $this->faker->numberBetween(100, 1000),
            'estimated_cost_usd' => 0.012345,
            'latency_ms' => $this->faker->numberBetween(500, 20000),
            'judge_model_version' => 'jev-1',
            'audit_prompt_version' => '1.0.0',
        ];
    }

    /**
     * A run in which the judge was unreachable for at least one competency
     * (D6/AD-3) — some indicators are unavailable, but not all.
     */
    public function partial(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => AuditRunStatus::Partial->value,
            'failure_reason' => 'judge_unavailable',
            'indicators_total' => 5,
            'indicators_judged' => 3,
            'indicators_skipped' => 0,
            'indicators_unavailable' => 2,
            'indicators_malformed' => 0,
        ]);
    }

    /**
     * A run in which every judgeable indicator ended unavailable — the
     * KNOWN CEILING state `->failed()` writes for a worker kill (D6).
     */
    public function failed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => AuditRunStatus::Failed->value,
            'failure_reason' => 'job_killed',
            'indicators_total' => 0,
            'indicators_judged' => 0,
            'indicators_skipped' => 0,
            'indicators_unavailable' => 0,
            'indicators_malformed' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => null,
            'latency_ms' => 0,
        ]);
    }
}
