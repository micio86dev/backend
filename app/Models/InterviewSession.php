<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InterviewSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped InterviewSession model (C7a — Interview Session Mechanics).
 *
 * One row per competency attempt per participant. One session = one competency,
 * delivered in project_competencies.position order.
 *
 * Extends TenantModel:
 * - TenantScoped global scope: all queries filtered by organization_id automatically.
 * - TenantScoped creating listener: organization_id stamped unconditionally from resolver.
 *
 * Status LOCKED enum: {pending, in_corso, completed, timeout, skipped, error}
 *   pending   — row created, provider call not yet made
 *   in_corso  — provider session successfully issued; interview is live
 *   completed — ended normally
 *   timeout   — ended by time-out
 *   skipped   — ended by skip
 *   error     — provider hard-failure
 *
 * ended_reason ∈ {completed, timeout, skipped, error}
 *
 * framework_version_id: copied from project at session creation; NEVER re-derived.
 * This records which framework version scored this session, independent of future project changes.
 *
 * started_at / ended_at: cast as immutable datetime (timestampTz in DB).
 *
 * UNIQUE(participant_id, competency_code): one session per participant per competency.
 * Concurrent /start hitting this UNIQUE constraint → catch UniqueConstraintViolationException → RESUME.
 *
 * Security:
 * - organization_id NOT in $fillable — stamped by TenantScoped.creating unconditionally.
 *
 * REQ: InterviewSession tenant model (C7a)
 *
 * @property int $id
 * @property int $organization_id
 * @property int $participant_id
 * @property int $project_id
 * @property int $question_index
 * @property string $competency_code
 * @property int $framework_version_id
 * @property string $provider
 * @property string|null $provider_session_ref
 * @property string $status
 * @property string|null $ended_reason
 * @property int $error_count
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InterviewSession extends TenantModel
{
    /** @use HasFactory<InterviewSessionFactory> */
    use HasFactory;

    /**
     * How many times a single competency may reach `error` before it is closed for
     * good (interview-continuous-flow, D1/D4).
     *
     * Two, meaning the candidate gets ONE re-offer after the first failure. This
     * mirrors the already-ratified scoring retry semantics in CLAUDE.md — "exactly
     * 1 retry; after a failed retry → completed (definitive)" — so the product has
     * one retry rule rather than two that have to be explained separately.
     *
     * The bound governs the AUTOMATIC path only. Operator-initiated recovery
     * (`RecoverFailedParticipant`) stays deliberately unbounded: an operator acting
     * on a known incident is not the failure mode this ceiling exists to contain.
     */
    public const MAX_ERROR_ATTEMPTS = 2;

    /**
     * Mass-assignable attributes.
     *
     * organization_id is intentionally excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'participant_id',
        'project_id',
        'question_index',
        'competency_code',
        'framework_version_id',
        'provider',
        'provider_session_ref',
        'status',
        'ended_reason',
        'started_at',
        'ended_at',
    ];

    /**
     * Attribute casts.
     *
     * started_at / ended_at: immutable datetime (timestampTz in DB).
     * Immutable ensures accidental mutation of the Carbon instance is detected early.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The participant this session belongs to.
     *
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /**
     * The project this session belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The framework version pinned at session creation time.
     *
     * Immutable: copied from project.framework_version_id at creation; never re-derived.
     *
     * @return BelongsTo<FrameworkVersion, $this>
     */
    public function frameworkVersion(): BelongsTo
    {
        return $this->belongsTo(FrameworkVersion::class);
    }

    /**
     * Live transcript utterances for this session.
     *
     * HeyGen: replaced by authoritative server transcript at /end.
     * Tavus: best-effort live rows; not replaced at /end.
     *
     * @return HasMany<Utterance, $this>
     */
    public function utterances(): HasMany
    {
        return $this->hasMany(Utterance::class);
    }

    /**
     * Proctoring events for this session (best-effort, 13 canonical kinds).
     *
     * @return HasMany<IntegrityEvent, $this>
     */
    public function integrityEvents(): HasMany
    {
        return $this->hasMany(IntegrityEvent::class);
    }

    /**
     * JPEG proctoring snapshots for this session (stored on S3).
     *
     * @return HasMany<InterviewSnapshot, $this>
     */
    public function interviewSnapshots(): HasMany
    {
        return $this->hasMany(InterviewSnapshot::class);
    }
}
