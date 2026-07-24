<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ImmutableProjectException;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Org-scoped Project model (C4 Project Configuration).
 *
 * Extends TenantModel — organization_id is auto-stamped by TenantScoped.creating
 * and MUST NOT be in $fillable (prevents payload manipulation).
 *
 * framework_version_id: reference-pin; immutable from creation (prohibited in all PATCH).
 * assessment_type: 'standard'|'potential'; immutable once status='active' or 'archived'.
 * role_code: required for standard (ICO/FLL/MLL/BUL/SRX), null for potential; immutable once active/archived.
 * webhook_secret: encrypted at rest via 'encrypted' cast AND hidden in serialization.
 *
 * Model guards (backstop for non-HTTP paths):
 *   - updating: throws ImmutableProjectException on immutable field change when resulting status=active
 *   - updating: throws ImmutableProjectException on forbidden lifecycle transitions
 *
 * @property int $id
 * @property int $organization_id
 * @property int $framework_version_id
 * @property string $slug
 * @property string $name
 * @property string $assessment_type
 * @property string|null $role_code
 * @property string $language
 * @property string $status
 * @property int|null $pause_every_n_competencies
 * @property int|null $nudge_min_chars
 * @property string|null $exit_redirect_url
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property Carbon|null $deadline_at
 * @property Carbon|null $goes_live_at
 * @property Carbon|null $deleted_at
 */
class Project extends TenantModel
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * organization_id intentionally absent — stamped by TenantScoped.creating.
     * Including it would allow payload manipulation to cross org boundaries.
     *
     * @var list<string>
     */
    protected $fillable = [
        'framework_version_id',
        'slug',
        'name',
        'assessment_type',
        'role_code',
        'language',
        'status',
        'pause_every_n_competencies',
        'nudge_min_chars',
        'exit_redirect_url',
        'webhook_url',
        'webhook_secret',
        'deadline_at',
        'goes_live_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        // pdo_pgsql returns bigint as string — explicit cast prevents false-positive
        // immutability rejection when comparing (int) against persisted value.
        'framework_version_id' => 'integer',
        'deadline_at' => 'datetime',
        'goes_live_at' => 'datetime',
        // encrypted at rest; also in $hidden to prevent serialization exposure.
        'webhook_secret' => 'encrypted',
    ];

    /**
     * webhook_secret is excluded from all serialized output (toArray/toJson/resource).
     * It is ALSO cast to 'encrypted' (DB encryption). Both are required.
     *
     * @var list<string>
     */
    protected $hidden = ['webhook_secret'];

    /**
     * Register model boot guards.
     *
     * Two updating guards:
     * 1. Immutability guard: rejects changes to assessment_type or role_code when the
     *    resulting status is 'active' or 'archived'. framework_version_id is always
     *    prohibited in PATCH at the FormRequest layer (not checked here).
     * 2. Lifecycle guard: rejects forbidden status transitions (only draft→active and
     *    active→archived are allowed).
     *
     * These are backstops for direct (non-HTTP) writes; the FormRequest layer
     * is the primary enforcement path for HTTP requests.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $project): void {
            // ── Immutability guard ──────────────────────────────────────────
            // assessment_type and role_code are immutable once status ∈ {active, archived}.
            // framework_version_id is always immutable after creation (blocked at FormRequest layer).
            $currentStatus = $project->getOriginal('status');
            $resultingStatus = $project->isDirty('status')
                ? $project->status
                : $currentStatus;

            $immutableStatuses = ['active', 'archived'];
            if ((in_array($currentStatus, $immutableStatuses, true) || in_array($resultingStatus, $immutableStatuses, true))
                && $project->isDirty(['assessment_type', 'framework_version_id', 'role_code'])
            ) {
                throw new ImmutableProjectException(
                    'Cannot change immutable fields on an active or archived project.'
                );
            }

            // ── Lifecycle guard ─────────────────────────────────────────────
            // Approved transitions: draft→active, active→archived.
            // All other transitions are forbidden.
            if ($project->isDirty('status')) {
                $origStatus = $project->getOriginal('status');
                $newStatus = $project->status;

                $allowed = [
                    ['draft',  'active'],
                    ['active', 'archived'],
                ];

                if (! in_array([$origStatus, $newStatus], $allowed, true)) {
                    throw new ImmutableProjectException(
                        "Invalid status transition: '{$origStatus}' → '{$newStatus}' is not allowed."
                    );
                }
            }
        });
    }

    /**
     * The organization that owns this project.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The FrameworkVersion pinned by this project.
     *
     * @return BelongsTo<FrameworkVersion, $this>
     */
    public function frameworkVersion(): BelongsTo
    {
        return $this->belongsTo(FrameworkVersion::class);
    }

    /**
     * The competencies assigned to this project.
     *
     * Table: project_competencies; pivot column: position.
     * Ordered by position for consistent ordering.
     *
     * @return BelongsToMany<Competency, $this>
     */
    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(Competency::class, 'project_competencies')
            ->withPivot('position')
            ->orderByPivot('position');
    }
}
