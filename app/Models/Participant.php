<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ParticipantTransitionException;
use Database\Factories\ParticipantFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int $organization_id
 * @property int $project_id
 * @property string $candidate_ref
 * @property string $display_name
 * @property string|null $role_code
 * @property string|null $language
 * @property 'in_attesa'|'in_corso'|'in_valutazione'|'completato'|'errore' $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Participant extends Model implements AuthenticatableContract, JWTSubject
{
    /** @use HasFactory<ParticipantFactory> */
    use Authenticatable, HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * organization_id is intentionally excluded — set ONLY server-side from
     * $project->organization_id (named security invariant).
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'candidate_ref',
        'display_name',
        'role_code',
        'language',
        'status',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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

            $from = $participant->getOriginal('status');
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
}
