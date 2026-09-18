<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Audit\AuditOutcomeReason;
use App\Enums\Audit\AuditVerdictStatus;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for IndicatorScoreAudit (scoring-audit-jev P2).
 *
 * Defaults to a `judged` verdict. Named states cover the other three
 * `AuditVerdictStatus` values — each keeps the judged/probability and
 * judged/outcome_reason equivalence CHECKs satisfied (design D4/C-E).
 *
 * NOTE: organization_id is NOT fillable — stamped by TenantScoped.creating.
 * NOTE: No updated_at — append-only, same shape as AiRequestFactory.
 *
 * @extends Factory<IndicatorScoreAudit>
 */
class IndicatorScoreAuditFactory extends Factory
{
    protected $model = IndicatorScoreAudit::class;

    /**
     * Define the model's default state (a usable, judged verdict).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audit_run_id' => IndicatorScoreAuditRun::factory(),
            'indicator_score_id' => IndicatorScore::factory(),
            'status' => AuditVerdictStatus::Judged->value,
            'support_probability' => $this->faker->randomFloat(4, 0.5, 1.0),
            'question_probabilities' => [
                'relevance' => $this->faker->randomFloat(4, 0.5, 1.0),
                'calibration' => $this->faker->randomFloat(4, 0.5, 1.0),
                'grounding' => $this->faker->randomFloat(4, 0.5, 1.0),
            ],
            'outcome_reason' => null,
        ];
    }

    /**
     * Out of judgment scope by the evidence-presence rule (D5) — never sent
     * to the judge.
     */
    public function skipped(AuditOutcomeReason $reason = AuditOutcomeReason::UnassessableByConstruction): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => AuditVerdictStatus::Skipped->value,
            'support_probability' => null,
            'question_probabilities' => null,
            'outcome_reason' => $reason->value,
        ]);
    }

    /**
     * The competency-level judge call itself threw — the vendor could not
     * be asked at all.
     */
    public function unavailable(AuditOutcomeReason $reason = AuditOutcomeReason::JudgeUnreachable): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => AuditVerdictStatus::Unavailable->value,
            'support_probability' => null,
            'question_probabilities' => null,
            'outcome_reason' => $reason->value,
        ]);
    }

    /**
     * The vendor answered, but this subject's verdict could not be used.
     */
    public function malformed(AuditOutcomeReason $reason = AuditOutcomeReason::VerdictMissing): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => AuditVerdictStatus::Malformed->value,
            'support_probability' => null,
            'question_probabilities' => null,
            'outcome_reason' => $reason->value,
        ]);
    }
}
