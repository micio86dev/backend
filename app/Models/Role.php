<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * Global Role model (C3 Framework Catalog).
 *
 * GLOBAL — NOT tenant-scoped. Roles are shared across all organizations.
 * Extends plain Model (NOT TenantModel).
 *
 * `revision_id` (framework-catalogue-authoring PR1, D1): NOT NULL, defaults
 * to the baseline revision at the DB level (see the backfill migration) so
 * every existing factory/direct-create call site across the suite keeps
 * working without having to name a revision explicitly.
 *
 * @property string $code
 * @property int $revision_id
 * @property string $name (resolved via current locale)
 * @property string $responsibilities (resolved via current locale)
 */
class Role extends Model
{
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
