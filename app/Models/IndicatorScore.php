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
 * Score domain: {1, 3, 5} (assessed) ∪ {-1} (unassessable sentinel).
 * Validated by IndicatorValidator before persistence.
 *
 * excerpts: persisted as original LLM array (not whitespace-normalized).
 * Substring validation is performed at scoring time with normalization applied
 * transiently; the stored form is verbatim from the LLM.
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
    ];

    /**
     * Attribute casts.
     *
     * score: integer (smallint in DB; values {1,3,5,-1}).
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
