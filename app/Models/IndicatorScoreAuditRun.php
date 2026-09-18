<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Audit\AuditRunStatus;
use Database\Factories\IndicatorScoreAuditRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped IndicatorScoreAuditRun model (scoring-audit-jev, design D4).
 *
 * One row per operator-triggered audit invocation over one evaluation.
 * Append-only, exactly like `AiRequest`: `created_at` only, no `updated_at`,
 * no UPDATE path in business logic — enforced by
 * `tests/Arch/Audit/AuditAppendOnlyArchTest.php`. `AuditVerdictReader` is the
 * ONLY class in `app/` permitted to query this model (D10).
 *
 * Extends TenantModel (C2) — automatically scoped by organization_id.
 *
 * Security:
 * - organization_id NOT in $fillable — stamped by TenantScoped.creating unconditionally.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $evaluation_id
 * @property int|null $requested_by_user_id
 * @property AuditRunStatus $status
 * @property string|null $failure_reason
 * @property int $indicators_total
 * @property int $indicators_judged
 * @property int $indicators_skipped
 * @property int $indicators_unavailable
 * @property int $indicators_malformed
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $estimated_cost_usd
 * @property int $latency_ms
 * @property string $judge_model_version
 * @property string $audit_prompt_version
 * @property Carbon $created_at
 */
class IndicatorScoreAuditRun extends TenantModel
{
    /** @use HasFactory<IndicatorScoreAuditRunFactory> */
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
        'evaluation_id',
        'requested_by_user_id',
        'status',
        'failure_reason',
        'indicators_total',
        'indicators_judged',
        'indicators_skipped',
        'indicators_unavailable',
        'indicators_malformed',
        'input_tokens',
        'output_tokens',
        'estimated_cost_usd',
        'latency_ms',
        'judge_model_version',
        'audit_prompt_version',
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
            'status' => AuditRunStatus::class,
            'indicators_total' => 'integer',
            'indicators_judged' => 'integer',
            'indicators_skipped' => 'integer',
            'indicators_unavailable' => 'integer',
            'indicators_malformed' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'latency_ms' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The evaluation this audit run covers.
     *
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /**
     * The per-indicator verdict rows this run wrote.
     *
     * @return HasMany<IndicatorScoreAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(IndicatorScoreAudit::class, 'audit_run_id');
    }
}
