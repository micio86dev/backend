<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Audit\AuditOutcomeReason;
use App\Enums\Audit\AuditVerdictStatus;
use Database\Factories\IndicatorScoreAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped IndicatorScoreAudit model (scoring-audit-jev, design D4).
 *
 * One row per indicator covered by an audit run — every judgeable and
 * skipped indicator gets a row, always (AD-4): absence of a row for an
 * in-scope indicator is never a legal outcome. Append-only, exactly like
 * `AiRequest` — enforced by `tests/Arch/Audit/AuditAppendOnlyArchTest.php`.
 * `AuditVerdictReader` is the ONLY class in `app/` permitted to query this
 * model (D10).
 *
 * `outcome_reason`, NOT `skip_reason` (design C-E): this column also carries
 * `unavailable` and `malformed` reasons, and a column literally named
 * `skip_reason` on an `unavailable` row would say something false about the
 * row it is on.
 *
 * Extends TenantModel (C2) — automatically scoped by organization_id.
 *
 * Security:
 * - organization_id NOT in $fillable — stamped by TenantScoped.creating unconditionally.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $audit_run_id
 * @property int $indicator_score_id
 * @property AuditVerdictStatus $status
 * @property string|null $support_probability
 * @property array<string, float>|null $question_probabilities
 * @property AuditOutcomeReason|null $outcome_reason
 * @property Carbon $created_at
 */
class IndicatorScoreAudit extends TenantModel
{
    /** @use HasFactory<IndicatorScoreAuditFactory> */
    use HasFactory;

    /**
     * Disable automatic timestamp management.
     *
     * This model is append-only: only 'created_at' exists (set by DB default).
     * 'updated_at' does NOT exist in the schema — never set by Eloquent.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Manually managed created_at (for DB insert to pick up useCurrent()).
     *
     * @var string|null
     */
    const CREATED_AT = 'created_at';

    /**
     * Mass-assignable attributes.
     *
     * organization_id intentionally excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'audit_run_id',
        'indicator_score_id',
        'status',
        'support_probability',
        'question_probabilities',
        'outcome_reason',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'status' => AuditVerdictStatus::class,
            'support_probability' => 'decimal:4',
            'question_probabilities' => 'array',
            'outcome_reason' => AuditOutcomeReason::class,
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The run this verdict belongs to.
     *
     * @return BelongsTo<IndicatorScoreAuditRun, $this>
     */
    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(IndicatorScoreAuditRun::class, 'audit_run_id');
    }

    /**
     * The persisted indicator score this verdict judges.
     *
     * @return BelongsTo<IndicatorScore, $this>
     */
    public function indicatorScore(): BelongsTo
    {
        return $this->belongsTo(IndicatorScore::class);
    }
}
