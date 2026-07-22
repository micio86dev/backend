<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationStatus;
use Database\Factories\EvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped Evaluation model (C9 — Scoring Engine).
 *
 * Root entity for a participant's BARS evaluation run.
 * One Evaluation per participant (unique participant_id constraint).
 *
 * Extends TenantModel (C2) — automatically scoped by organization_id.
 *
 * Status lifecycle: processing → completed | pending.
 * 'evaluated_at' is null while status = processing; set on terminal transition.
 *
 * Security:
 * - organization_id NOT in $fillable — stamped by TenantScoped.creating unconditionally.
 *
 * REQ: Evaluation tenant model (C9 D1)
 *
 * @property int $id
 * @property int $organization_id
 * @property int $participant_id
 * @property EvaluationStatus $status
 * @property int $framework_version_id
 * @property string $model_version
 * @property string $prompt_version
 * @property Carbon|null $evaluated_at
 * @property bool $retry_attempt
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Evaluation extends TenantModel
{
    /** @use HasFactory<EvaluationFactory> */
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * organization_id intentionally excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'participant_id',
        'status',
        'framework_version_id',
        'model_version',
        'prompt_version',
        'evaluated_at',
        'retry_attempt',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => EvaluationStatus::class,
            'evaluated_at' => 'immutable_datetime',
            'retry_attempt' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The participant this evaluation belongs to.
     *
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /**
     * The framework version pinned at job start.
     *
     * Immutable after creation — never re-derived from live project state.
     *
     * @return BelongsTo<FrameworkVersion, $this>
     */
    public function frameworkVersion(): BelongsTo
    {
        return $this->belongsTo(FrameworkVersion::class);
    }

    /**
     * Competency-level results for this evaluation.
     *
     * @return HasMany<CompetencyResult, $this>
     */
    public function competencyResults(): HasMany
    {
        return $this->hasMany(CompetencyResult::class);
    }
}
