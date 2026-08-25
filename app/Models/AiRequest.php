<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiRequestFailureReason;
use Database\Factories\AiRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped AiRequest model (C9 — Scoring Engine).
 *
 * Append-only audit log for every LLM call made during scoring.
 * NEVER updated after INSERT — no update() in business logic.
 *
 * Extends TenantModel (C2) — automatically scoped by organization_id.
 *
 * Design invariants (D1):
 * - $timestamps = false: only created_at is persisted (append-only audit trail).
 * - No updated_at column exists in the DB schema.
 * - organization_id NOT in $fillable — stamped by TenantScoped.creating.
 * - evaluation_id is always set at INSERT time (Evaluation row exists at job START);
 *   the nullable FK in schema is a DB-level safety net only.
 *
 * REQ: AiRequest tenant model (C9 D1)
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $evaluation_id
 * @property string $competency_code
 * @property string $model
 * @property string $prompt_version
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $finish_reason
 * @property int|null $response_bytes
 * @property bool|null $response_fenced
 * @property string|null $response_sha256
 * @property int $latency_ms
 * @property Carbon $created_at
 */
class AiRequest extends TenantModel
{
    /** @use HasFactory<AiRequestFactory> */
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
        'provider',
        'estimated_cost_usd',
        'success',
        'failure_reason',
        'competency_code',
        'model',
        'prompt_version',
        'input_tokens',
        'output_tokens',
        'finish_reason',
        'response_bytes',
        'response_fenced',
        'response_sha256',
        'latency_ms',
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
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'success' => 'boolean',
            'failure_reason' => AiRequestFailureReason::class,
            'response_bytes' => 'integer',
            'response_fenced' => 'boolean',
            'latency_ms' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The evaluation this LLM request is logged under.
     *
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }
}
