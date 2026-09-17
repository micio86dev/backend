<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BumpsRevisionContentVersion;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;
use Spatie\Translatable\HasTranslations;

/**
 * Global Role model (C3 Framework Catalog).
 *
 * GLOBAL — NOT tenant-scoped. Roles are shared across all organizations.
 * Extends plain Model (NOT TenantModel).
 *
 * `revision_id` (framework-catalogue-authoring PR1, D1): NOT NULL. Defaulted
 * to the baseline revision at the DB level ONLY until PR3
 * (`2026_09_15_201435_drop_catalogue_revision_defaults`), which drops that
 * DEFAULT (review advisory R3-001). `booted()` below now provides the SAME
 * "lands in the baseline unless told otherwise" behaviour at the model
 * layer — see the identical note on `Competency`.
 *
 * @property string $code
 * @property int $revision_id
 * @property string $name (resolved via current locale)
 * @property string $responsibilities (resolved via current locale)
 */
class Role extends Model
{
    use BumpsRevisionContentVersion;

    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasTranslations;

    /**
     * Custom table name — prefixed to avoid collision with spatie/laravel-permission `roles` table.
     */
    protected $table = 'framework_roles';

    /**
     * @var list<string>
     */
    public array $translatable = ['name', 'responsibilities'];

    /**
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'responsibilities', 'revision_id'];

    /**
     * `revision_id` no longer carries a DB-level DEFAULT (framework-
     * catalogue-authoring PR3, `2026_09_15_201435_drop_catalogue_revision_
     * defaults`) — see the identical note and rationale on
     * `Competency::booted()`.
     */
    protected static function booted(): void
    {
        static::creating(function (self $role): void {
            // `getAttribute()`, not magic property access — see the
            // identical note on `Competency::booted()`.
            if ($role->getAttribute('revision_id') === null && Schema::hasColumn('framework_roles', 'revision_id')) {
                $role->revision_id = FrameworkCatalogRevision::where('is_baseline', true)->value('id');
            }
        });

        static::bumpRevisionContentVersionListeners();
    }

    /**
     * The catalogue revision this row belongs to (framework-catalogue-authoring PR1, D1).
     *
     * @return BelongsTo<FrameworkCatalogRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(FrameworkCatalogRevision::class, 'revision_id');
    }

    /**
     * Competencies assigned to this role (ordered by pivot position).
     *
     * `withPivotValue('revision_id', $this->revision_id)` (framework-
     * catalogue-authoring PR4b, K1): `revision_id` is not a `withPivot()`
     * attribute, so a plain `sync()`/`attach()` omitted it from the INSERT
     * entirely and Postgres fell back to the column's own DEFAULT — a fixed
     * baseline id baked in at migration time
     * (`2026_09_15_090004_backfill_baseline_revision.php`), never THIS
     * role's own revision. For an already-cloned pair the write was merely
     * a redundant UPDATE (harmless), but a genuinely NEW attachment — a
     * role gaining a competency it did not already have in the draft —
     * inserted a pivot row whose `role_id` belongs to the draft and whose
     * `revision_id` claims the baseline, which the composite FK
     * (`framework_role_competency_role_revision_fk`) correctly refuses
     * outright (`catalogue:import`, task 15.5's own `sync()` call is the
     * one production writer that reaches this). `withPivotValue()` scopes
     * both the WRITE (every attach/sync always names this role's own
     * revision) and every READ through this relation (an extra, defense-
     * in-depth `WHERE revision_id = ?`) — since a role and its own pivot
     * rows always share one revision by construction (D1), this is a no-op
     * restriction for every already-correct row and the actual fix for the
     * one write path that was not.
     *
     * @return BelongsToMany<Competency, $this>
     */
    public function competencies(): BelongsToMany
    {
        $relation = $this->belongsToMany(Competency::class, 'framework_role_competency')
            ->withPivot('position')
            ->orderBy('framework_role_competency.position');

        // Guarded (found by the full suite, not assumed): eager loading
        // (`Role::with('competencies')`) builds this relation's base query
        // against a BLANK, freshly-instantiated Role with no attributes set
        // yet — `revision_id` is null at that point, and `withPivotValue()`
        // refuses a null value outright. Every genuinely HYDRATED Role (the
        // only case that ever writes through this relation) always carries
        // a real `revision_id` (NOT NULL column). `getAttribute()`, not
        // `$this->revision_id` (same PHPStan note as `Competency::
        // booted()`'s own creating listener): the class docblock's
        // `@property int $revision_id` describes a HYDRATED row, so the
        // magic-property read would make PHPStan treat this null check as
        // statically impossible on a genuinely blank instance.
        $revisionId = $this->getAttribute('revision_id');

        if ($revisionId !== null) {
            $relation->withPivotValue('revision_id', $revisionId);
        }

        return $relation;
    }
}
