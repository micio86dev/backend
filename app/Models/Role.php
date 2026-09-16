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
     * @return BelongsToMany<Competency, $this>
     */
    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(Competency::class, 'framework_role_competency')
            ->withPivot('position')
            ->orderBy('framework_role_competency.position');
    }
}
