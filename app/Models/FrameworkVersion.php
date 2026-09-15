<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\DraftRevisionPinRejectedException;
use App\Exceptions\LockedFrameworkVersionException;
use Database\Factories\FrameworkVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped FrameworkVersion model (C3 Framework Catalog).
 *
 * Extends TenantModel (C2) — automatically scoped by organization_id.
 * is_locked: activated by C4 reference-pin. Once locked, mutation and deletion
 * are blocked by the immutability guard in booted().
 *
 * C4 changes:
 *   - Removed is_locked from $fillable (pin-only via explicit property assignment).
 *   - Replaced RuntimeException with LockedFrameworkVersionException (renders HTTP 422).
 *   - Replaced projects() placeholder with real hasMany(Project::class).
 *
 * `revision_id` (framework-catalogue-authoring PR1, D1): NULLABLE (unlike
 * every catalogue-content table) — see the migration's own docblock for why.
 * FILLABLE, unlike `is_locked`: a pin explicitly names the revision it
 * resolves to, and `booted()`'s new guard refuses the assignment outright
 * when the target revision is still `draft`, so there is no unguarded path
 * for mass-assignment to abuse.
 *
 * @property string $version
 * @property bool $is_locked
 * @property string|null $label
 * @property int|null $revision_id
 */
class FrameworkVersion extends TenantModel
{
    /** @use HasFactory<FrameworkVersionFactory> */
    use HasFactory;

    /**
     * is_locked is intentionally excluded from $fillable.
     * Lock is controlled exclusively via the pin transaction:
     *   $fv->is_locked = true; $fv->save()  (with lockForUpdate conditional).
     * organization_id MUST remain fillable for factory/seeder/CLI usage.
     *
     * @var list<string>
     */
    protected $fillable = ['organization_id', 'version', 'label', 'revision_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_locked' => 'boolean',
    ];

    /**
     * Register the immutability guard on boot.
     *
     * Blocks deletion AND mutation of any locked FrameworkVersion.
     * Throws LockedFrameworkVersionException (renders HTTP 422) — not a bare RuntimeException.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::deleting(function (self $fv): void {
            if ($fv->is_locked) {
                throw new LockedFrameworkVersionException(
                    "FrameworkVersion [{$fv->id}] is locked and cannot be deleted."
                );
            }
        });

        static::updating(function (self $fv): void {
            if ($fv->getOriginal('is_locked') === true) {
                throw new LockedFrameworkVersionException(
                    "FrameworkVersion [{$fv->id}] is locked and cannot be mutated."
                );
            }

            self::refuseDraftRevisionTarget($fv);
        });

        static::creating(function (self $fv): void {
            self::assignLatestPublishedRevisionIfUnset($fv);
            self::refuseDraftRevisionTarget($fv);
        });
    }

    /**
     * The pin gap, closed (framework-catalogue-authoring, "GAP FOUND DURING
     * PR 1" / G1-G2): PR1's backfill stamps every row that existed at
     * migration time, but nothing assigned `revision_id` for a NEW
     * `FrameworkVersion` created afterward — it stayed null, and
     * `Evaluation -> FrameworkVersion -> revision -> rows` had nothing to
     * resolve, which is the entire correctness argument this change exists
     * for.
     *
     * A brand-new `FrameworkVersion` that does not already name a revision
     * resolves the LATEST published revision — never a draft (published
     * content is the only kind safe to pin against; a draft can still
     * change under it) and never null, as long as at least one published
     * revision exists (the baseline always does, from the moment
     * migrations run). Only fires when `revision_id` was never set at all
     * — an explicit caller (a test constructing a specific scenario, or a
     * future cross-revision affordance) is never overridden.
     *
     * DECIDED, NOT ASSUMED (G2's own instruction): the column STAYS
     * NULLABLE at the DB level rather than becoming `NOT NULL`. A blanket
     * `NOT NULL` would require a default for every existing/future creation
     * path — including this suite's own pre-revision-schema tests
     * (`BaselineRevisionMigrationTest` explicitly creates a `FrameworkVersion`
     * against the ROLLED-BACK schema, before this column exists at all) and
     * any environment where migrations have run but the seeder has not yet
     * populated a single published revision (`resolveBaselineRevision()`'s
     * own fail-closed posture already documents that this seeder ordering
     * assumption is not universal). This guard is what actually closes the
     * gap in the ordinary path; `NOT NULL` would only forbid the narrow set
     * of legitimate no-revision-yet states this application-level guard
     * does not need to forbid to be correct.
     */
    private static function assignLatestPublishedRevisionIfUnset(self $fv): void
    {
        // Guarded, not a bare query: `BaselineRevisionMigrationTest` creates
        // a FrameworkVersion against the deliberately-rolled-back PRE-
        // revision schema, where `framework_catalog_revisions` does not
        // exist yet.
        if ($fv->revision_id !== null || ! Schema::hasTable('framework_catalog_revisions')) {
            return;
        }

        $latestPublishedId = FrameworkCatalogRevision::where('state', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->value('id');

        if ($latestPublishedId !== null) {
            $fv->revision_id = $latestPublishedId;
        }
    }

    /**
     * Refuse a draft revision as a pin target (framework-catalogue-authoring
     * PR1, D1 — "A FrameworkVersion MUST NOT be creatable or resolvable
     * against a draft revision"). Only fires when `revision_id` is actually
     * being assigned/changed — a FrameworkVersion that never mentions a
     * revision (the overwhelming majority of this suite, today) is
     * unaffected.
     */
    private static function refuseDraftRevisionTarget(self $fv): void
    {
        if ($fv->revision_id === null || ! $fv->isDirty('revision_id')) {
            return;
        }

        $revision = FrameworkCatalogRevision::find($fv->revision_id);

        if ($revision !== null && $revision->state === 'draft') {
            throw new DraftRevisionPinRejectedException(
                "FrameworkVersion cannot be pinned to revision [{$revision->id}]: it is still a draft."
            );
        }
    }

    /**
     * The catalogue revision this version resolves to (framework-catalogue-authoring PR1, D1).
     *
     * @return BelongsTo<FrameworkCatalogRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(FrameworkCatalogRevision::class, 'revision_id');
    }

    /**
     * Projects that have pinned this FrameworkVersion.
     *
     * Wired by C4 — replaces the placeholder from C3.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
