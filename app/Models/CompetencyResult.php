<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompetencyResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped CompetencyResult model (C9 — Scoring Engine).
 *
 * One row per competency per evaluation. Maps to `COL{score, reliability}` in
 * the sample report (evaluation-report-example.json).
 *
 * Extends TenantModel (C2) — automatically scoped by organization_id.
 *
 * unscorable_reason domain values (string, not enum cast — values stable in PR1):
 *   'role_no_bars'               — no BARS catalog for this role+competency.
 *   'anchor_translation_missing' — project locale has missing anchor/indicator translation.
 *   'llm_parse_error'            — persistent malformed LLM response (count mismatch, invalid JSON,
 *                                   out-of-domain score after all parse retries).
 *
 * Security:
 * - organization_id NOT in $fillable — stamped by TenantScoped.creating unconditionally.
 *
 * REQ: CompetencyResult tenant model (C9 D1)
 *
 * @property int $id
 * @property int $organization_id
 * @property int $evaluation_id
 * @property string $competency_code
 * @property float|null $score
 * @property float $reliability
 * @property bool $valid
 * @property string|null $unscorable_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CompetencyResult extends TenantModel
{
    /** @use HasFactory<CompetencyResultFactory> */
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * organization_id intentionally excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'evaluation_id',
        'competency_code',
        'score',
        'reliability',
        'valid',
        'unscorable_reason',
    ];

    /**
     * Attribute casts.
     *
     * score: float nullable (CC2 — all-indicators-(-1) → NULL score).
     * reliability: float [0..1] stored as numeric(5,4).
     * valid: boolean.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'reliability' => 'float',
            'valid' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The evaluation this result belongs to.
     *
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /**
     * Individual indicator scores for this competency result.
     *
     * @return HasMany<IndicatorScore, $this>
     */
    public function indicatorScores(): HasMany
    {
        return $this->hasMany(IndicatorScore::class);
    }
}
