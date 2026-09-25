<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyMode;
use App\Enums\ParticipantSchedulingStatus;
use App\Exceptions\ParticipantTransitionException;
use App\Models\Concerns\HasPublicId;
use App\Support\PublicApi\PubliclyIdentifiable;
use Database\Factories\ParticipantFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Participant model (C6 — Participant + SSO Ingress).
 *
 * Represents a candidate participant in a project interview session.
 *
 * Security invariants:
 * - Does NOT extend TenantModel — avoids org-null-on-public-endpoint and
 *   guard-scope-null bugs; mirrors ApiClient pattern (plain Model).
 * - Does NOT use HasRoles — candidates have no Spatie roles.
 * - Does NOT extend Foundation\Auth\User — avoids Authorizable::can() conflict.
 * - organization_id MUST NOT be in $fillable — set only server-side from
 *   $project->organization_id (named security invariant; mirrors ApiClient key_hash rule).
 * - No SoftDeletes in C6 (GDPR/C13 concern).
 * - booted() updating guard: rejects illegal status transitions via
 *   ParticipantTransitionException (→ HTTP 422).
 *
 * Allowed transitions (CRITICAL-1 complete map, C6+C7a; participant-error-recovery
 * adds the errore → in_attesa recovery edge):
 *   in_attesa → in_corso | errore
 *   in_corso  → in_valutazione | errore
 *   in_valutazione → completato | errore
 *   completato → [] (terminal)
 *   errore     → in_attesa (ONLY via App\Actions\Participant\RecoverFailedParticipant)
 *
 * REQ: Participant Model and Schema, Participant Model Lifecycle Guard
 *
 * @property int $id
 * @property string $public_id BEAI Public API (`/v1`) external id, bare ULID — public-api step 5, G-05.
 * @property int $organization_id
 * @property int $project_id
 * @property string $candidate_ref
 * @property string $display_name
 * @property string $email
 * @property string|null $role_code
 * @property string|null $language
 * @property 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore' $status
 * @property array<string, string>|null $metadata Public API step 5 — client-owned free-form key/value pairs.
 * @property string|null $exit_redirect_url Public API step 5 — per-enrolment override of the project's own.
 * @property ApiKeyMode $mode Public API step 5 — live/test, stamped from the enrolling key.
 * @property string|null $session_token_jti Public API step 5 — jti of the current unconsumed session token.
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $scheduled_at
 * @property ParticipantSchedulingStatus|null $scheduling_status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Participant extends Model implements AuthenticatableContract, JWTSubject, PubliclyIdentifiable
{
    /** @use HasFactory<ParticipantFactory> */
    use Authenticatable, HasFactory, HasPublicId;

    /**
     * Mass-assignable attributes.
     *
     * organization_id is intentionally excluded — set ONLY server-side from
     * $project->organization_id (named security invariant).
     *
     * scheduled_at/scheduling_status are also intentionally excluded
     * (interview-scheduling, design AD-1) — every write path uses
     * forceFill(), mirroring the existing organization_id discipline; mass
     * assignment of a scheduling field from an uncontrolled array is exactly
     * the kind of surface this list is deliberately narrow to prevent.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'candidate_ref',
        'display_name',
        'email',
        'role_code',
        'language',
        'status',
    ];

    /**
     * Attribute casts.
     *
     * scheduled_at/scheduling_status (interview-scheduling, design AD-1): both
     * null together (never scheduled) or both non-null — enforced by the
     * `participants_scheduling_status_pair_check` DB CHECK constraint, not by
     * this cast list.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'scheduling_status' => ParticipantSchedulingStatus::class,
            'metadata' => 'array',
            'mode' => ApiKeyMode::class,
        ];
    }

    public static function publicIdPrefix(): string
    {
        return 'int_';
    }

    /**
     * Allowed status transitions — COMPLETE map (C7a CRITICAL-1).
     *
     * C6 defined: in_attesa→in_corso, in_corso→in_valutazione, in_valutazione→{completato,errore}.
     * C7a adds:   in_attesa→errore (hard-fail on first competency /start)
     *             in_corso→errore  (hard-fail on subsequent competency)
     *
     * 'completato' is an EXPLICIT key with an empty array — genuinely terminal, no
     * outbound edge, ever. 'errore' carries exactly ONE outbound edge (in_attesa) —
     * the participant-error-recovery recovery action; it is otherwise still terminal
     * for every other target (in_corso/in_valutazione/completato all rejected). The
     * ?? [] fallback exists only as a last resort for unrecognized states; both known
     * keys MUST appear explicitly so the intent is visible and auditable.
     *
     * IMPORTANT: 'started_at' is NOT in $fillable — use direct property assignment:
     *   $participant->started_at = now();
     *   $participant->status     = 'in_corso';
     *   $participant->save();
     * Using $participant->update(['started_at' => now()]) silently drops it (guarded).
     *
     * @var array<string, list<string>>
     */
    private static array $allowedTransitions = [
        'in_attesa' => ['in_corso', 'errore'],
        'in_corso' => ['in_valutazione', 'errore'],
        'in_valutazione' => ['completato', 'errore'],
        'completato' => [],   // terminal — no outbound transitions (FIX-5)
        // (participant-error-recovery D2) ONE authorized recovery edge — written
        // ONLY by App\Actions\Participant\RecoverFailedParticipant. Still terminal
        // for every other transition target.
        'errore' => ['in_attesa'],
    ];

    /**
     * Register model boot guards.
     *
     * Updating guard: rejects illegal status transitions.
     * C6 only fires create → in_attesa; this guard is a backstop for C7/C9 writes
     * and any direct (non-HTTP) status mutation.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $participant): void {
            if (! $participant->isDirty('status')) {
                return;
            }

            // getOriginal() is declared mixed — status is always a string
            // column, but narrowed explicitly here (public-api step 5
            // phpstan --level=max follow-up, this file being touched for
            // HasPublicId) rather than trusted, since it is both an array
            // key and interpolated into the exception message below.
            $fromRaw = $participant->getOriginal('status');
            $from = is_string($fromRaw) ? $fromRaw : '';
            $to = $participant->status;

            $allowed = self::$allowedTransitions[$from] ?? [];

            if (! in_array($to, $allowed, true)) {
                throw new ParticipantTransitionException(
                    "Invalid status transition: '{$from}' → '{$to}' is not allowed."
                );
            }
        });
    }

    // -------------------------------------------------------------------------
    // JWTSubject implementation
    // -------------------------------------------------------------------------

    /**
     * Return the JWT identifier (sub claim) — the participant's DB primary key.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return custom JWT claims merged into the candidate token.
     *
     * These are INFORMATIONAL — the guard never trusts them for scoping.
     * TenantContextCandidate reads org exclusively from the DB record.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The project this participant belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The organization this participant belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The timeline events recorded for this enrolment (public-api step 5,
     * G-34) — `GET /v1/interviews/{id}/events`.
     *
     * @return HasMany<InterviewEvent, $this>
     */
    public function interviewEvents(): HasMany
    {
        return $this->hasMany(InterviewEvent::class);
    }
}
