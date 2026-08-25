<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IndicatorScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped IndicatorScore model (C9 — Scoring Engine).
 *
 * One row per BARS indicator per competency result. Maps to `behaviors[]{}`
 * in the sample report (esempio-report-valutazione.json).
 *
 * Extends TenantModel (C2) — automatically scoped by organization_id.
 *
 * Score domain: {1, 2, 3, 4, 5} (assessed) ∪ {-1} (unassessable sentinel).
 * Widened from {1,3,5,-1} per AD-1/D4 (bars-full-scale-1-5) — 2 and 4 are
 * RESIDUAL levels, legal only per PromptBuilder's SCORING_PROCEDURE tie-break.
 * Validated by IndicatorValidator before persistence.
 *
 * excerpts: persisted as original LLM array (not whitespace-normalized).
 * Substring validation is performed at scoring time with normalization applied
 * transiently; the stored form is verbatim from the LLM.
 *
 * unassessable_reason (C13, scoring-failure-containment D1/D7 — product
 * owner override, 0.4 in tasks.md): nullable, WHY this row's score is -1 —
 * `model_declared`, `excerpt_unverifiable`, or `score_illegal` (App\Enums\
 * IndicatorFailureReason). Equivalence-CHECKed at the DB level
 * (`indicator_scores_unassessable_reason_check`): `(score = -1) =
 * (unassessable_reason IS NOT NULL)`. METADATA ONLY — no scoring formula
 * (MeanCalculator, AssessableFractionReliability, CompletionGate) may read it
 * (D9, arch-tested).
 *
 * Security:
 * - organization_id NOT in $fillable — stamped by TenantScoped.creating unconditionally.
 *
 * REQ: IndicatorScore tenant model (C9 D1)
 *
 * @property int $id
 * @property int $organization_id
 * @property int $competency_result_id
 * @property int $position
 * @property string $indicator_text
 * @property int $score
 * @property string $explanation
 * @property array<int, string> $excerpts
 * @property string|null $unassessable_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class IndicatorScore extends TenantModel
{
    /** @use HasFactory<IndicatorScoreFactory> */
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * organization_id intentionally excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'competency_result_id',
        'position',
        'indicator_text',
        'score',
        'explanation',
        'excerpts',
        'unassessable_reason',
    ];

    /**
     * Attribute casts.
     *
     * score: integer (smallint in DB; values {1,2,3,4,5,-1}).
     * excerpts: array (JSON column deserialized to PHP array).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'excerpts' => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The competency result this indicator score belongs to.
     *
     * @return BelongsTo<CompetencyResult, $this>
     */
    public function competencyResult(): BelongsTo
    {
        return $this->belongsTo(CompetencyResult::class);
    }
}
