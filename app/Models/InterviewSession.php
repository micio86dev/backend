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
 * started_at (interview-session-started-at, D2): the FIRST live moment,
 * written once (`??= now()`) at the `in_corso` transition, never
 * overwritten by a resume. ended_at is stamped at `/end`. Consequence,
 * stated plainly: `ended_at - started_at` is a WALL-CLOCK SPAN that MAY
 * include an abandonment gap across a resume — it is NOT the session's
 * duration and MUST NEVER be read as one. The duration is
 * `liveSeconds()`: the sum of every CLOSED `interview_session_live_periods`
 * row, which a resume closes and reopens rather than leaving the original
 * span open across the gap (D1/D3).
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
 * @property int $error_count\n * @property Carbon|null $transcript_harvested_at
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

    /**
     * Every stretch this session held a live provider session
     * (interview-session-started-at, D1). `SessionLiveClock` is the only
     * writer.
     *
     * @return HasMany<InterviewSessionLivePeriod, $this>
     */
    public function livePeriods(): HasMany
    {
        return $this->hasMany(InterviewSessionLivePeriod::class);
    }

    // -------------------------------------------------------------------------
    // Derived duration
    // -------------------------------------------------------------------------

    /**
     * The session's recorded duration: the SUM of every CLOSED live period,
     * never the wall-clock span between `started_at` and `ended_at`
     * (interview-session-started-at, D1/D3).
     *
     * An open period (a resume mid-flight, or a session still `in_corso`)
     * contributes NOTHING and is excluded — mirrors
     * `operator-participant-visibility` D4: clamping an open period with
     * `now()` would report weeks for an abandoned tab.
     *
     * `null` when there are no CLOSED periods at all — never `0`. `0` reads
     * as "this session ran, and took no time", which is false for a session
     * that has not finished a single stretch yet.
     *
     * Call sites MUST eager-load `livePeriods` when iterating many sessions
     * (`SessionReviewController::index`, `ParticipantInterviewAggregator`) —
     * this method never issues its own query.
     */
    public function liveSeconds(): ?int
    {
        $closed = $this->livePeriods->filter(fn (InterviewSessionLivePeriod $period): bool => $period->ended_at !== null);

        if ($closed->isEmpty()) {
            return null;
        }

        return (int) $closed->sum(
            fn (InterviewSessionLivePeriod $period): int => $period->ended_at->getTimestamp() - $period->started_at->getTimestamp()
        );
    }
}
