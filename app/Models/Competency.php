<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompetencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Translatable\HasTranslations;

/**
 * Global Competency model (C3 Framework Catalog).
 *
 * GLOBAL — NOT tenant-scoped. Competencies are shared across all organizations.
 * type: 'standard' (18 seeded competencies) | 'potential' (MTG/LAT — pending authoring).
 *
 * `revision_id` (framework-catalogue-authoring PR1, D1): NOT NULL, defaults
 * to the baseline revision at the DB level (see the backfill migration) so
 * every existing factory/direct-create call site across the suite keeps
 * working without having to name a revision explicitly.
 *
 * @property string $code
 * @property int $revision_id
 * @property string $name (resolved via current locale)
 * @property string $definition (resolved via current locale)
 * @property string $type standard|potential
 * @property-read Pivot $pivot Pivot row when hydrated via Project::competencies() (carries the `position` column)
 */
class Competency extends Model
{
    /** @use HasFactory<CompetencyFactory> */
    use HasFactory;

    use HasTranslations;

    /**
     * Custom table name — prefixed to avoid collision with spatie/laravel-permission.
     */
    protected $table = 'framework_competencies';

    /**
     * @var list<string>
     */
    public array $translatable = ['name', 'definition'];

    /**
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'definition', 'type', 'revision_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => 'string',
    ];

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
     * Roles that include this competency.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'framework_role_competency');
    }
}
