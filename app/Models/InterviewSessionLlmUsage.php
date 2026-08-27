<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only conversation-LLM cost row per billed interview session
 * (pluggable-conversation-llm PR P6a/P6b, design D5/D10).
 *
 * APPEND-ONLY, mirroring `AiRequest` exactly: `$timestamps = false`, only
 * `created_at` exists in the schema (no `updated_at`), and business logic
 * must only ever `firstOrCreate()` a row — never `update()` or `delete()`
 * one. Enforced by `tests/Arch/Observability/LlmUsageAppendOnlyArchTest.php`.
 *
 * Written exactly once per session, keyed by the UNIQUE `interview_session_id`,
 * either from `InterviewController::end()` or from the daily
 * `beai:reconcile-llm-usage` sweep for a session that never reached `/end`.
 * `firstOrCreate()` on that unique column is what makes both a double `/end`
 * and a late reconcile sweep no-ops.
 *
 * `actual_input_tokens` / `actual_output_tokens` / `actual_cost_usd` are
 * PERMANENTLY NULL in `managed` mode — see the migration docblock. `rate_card`
 * is a SNAPSHOT: a later edit to `llm_models`' rate columns must never change
 * an already-stored `estimated_cost_usd`.
 *
 * EXEMPT from `PurgeExpiredDataCommand` — cost history has no subject matter
 * and must outlive the transcript purge.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $interview_session_id
 * @property int $turn_count
 * @property int|null $system_prompt_chars
 * @property int $participant_chars
 * @property int $avatar_chars
 * @property int|null $live_seconds
 * @property int $estimated_input_tokens
 * @property int $estimated_output_tokens
 * @property string|null $estimated_cost_usd
 * @property string $estimation_method
 * @property array<string, mixed> $rate_card
 * @property int|null $actual_input_tokens
 * @property int|null $actual_output_tokens
 * @property string|null $actual_cost_usd
 * @property Carbon $created_at
 */
class InterviewSessionLlmUsage extends TenantModel
{
    /**
     * `usage` does not pluralize to `usages` in the migration's table name —
     * Eloquent's default guess (`interview_session_llm_usages`) does not
     * match `interview_session_llm_usage` (the migration's literal name,
     * mirroring `ai_requests`' own singular-domain-noun convention).
     *
     * @var string
     */
    protected $table = 'interview_session_llm_usage';

    /**
     * Append-only: only `created_at` is persisted, set by the DB default.
     * `updated_at` does not exist in the schema.
     *
     * @var bool
     */
    public $timestamps = false;

    /** @var string|null */
    const CREATED_AT = 'created_at';

    /**
     * Mass-assignable attributes.
     *
     * organization_id intentionally excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'interview_session_id',
        'turn_count',
        'system_prompt_chars',
        'participant_chars',
        'avatar_chars',
        'live_seconds',
        'estimated_input_tokens',
        'estimated_output_tokens',
        'estimated_cost_usd',
        'estimation_method',
        'rate_card',
        'actual_input_tokens',
        'actual_output_tokens',
        'actual_cost_usd',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'turn_count' => 'integer',
            'system_prompt_chars' => 'integer',
            'participant_chars' => 'integer',
            'avatar_chars' => 'integer',
            'live_seconds' => 'integer',
            'estimated_input_tokens' => 'integer',
            'estimated_output_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'rate_card' => 'array',
            'actual_input_tokens' => 'integer',
            'actual_output_tokens' => 'integer',
            'actual_cost_usd' => 'decimal:6',
        ];
    }

    /**
     * The session this cost row belongs to.
     *
     * @return BelongsTo<InterviewSession, $this>
     */
    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }
}
