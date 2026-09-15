<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompetencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Schema;
use Spatie\Translatable\HasTranslations;

/**
 * Global Competency model (C3 Framework Catalog).
 *
 * GLOBAL — NOT tenant-scoped. Competencies are shared across all organizations.
 * type: 'standard' (18 seeded competencies) | 'potential' (MTG/LAT — pending authoring).
 *
 * `revision_id` (framework-catalogue-authoring PR1, D1): NOT NULL. Defaulted
 * to the baseline revision at the DB level ONLY until PR3
 * (`2026_09_15_201435_drop_catalogue_revision_defaults`), which drops that
 * DEFAULT (review advisory R3-001 — an omitted `revision_id` silently
 * landing in the published baseline was the actual risk). `booted()` below
 * now provides the SAME "lands in the baseline unless told otherwise"
 * behaviour at the model layer, for every Eloquent creation path, so every
 * existing factory/direct-create call site across the suite keeps working
 * without having to name a revision explicitly — a raw `DB::table()` insert
 * bypassing Eloquent entirely still gets a loud NOT NULL failure.
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
     * `revision_id` no longer carries a DB-level DEFAULT (framework-
     * catalogue-authoring PR3, `2026_09_15_201435_drop_catalogue_revision_
     * defaults`) — this restores the SAME "lands in the baseline unless
     * told otherwise" behaviour at the model layer instead, for every
     * Eloquent creation path (`new Competency; ->save()`,
     * `Competency::create()`, `Competency::factory()->create()` alike),
     * without silently absorbing a caller that DID name a revision. A raw
     * `DB::table('framework_competencies')->insert()` bypassing Eloquent
     * entirely still gets the loud NOT NULL failure the DEFAULT removal
     * exists to produce — deliberately not covered here.
     */
    protected static function booted(): void
    {
        static::creating(function (self $competency): void {
            // Guarded, not a bare query: `BaselineRevisionMigrationTest`
            // creates a Competency against the deliberately-rolled-back
            // PRE-revision schema, where neither this column nor
            // `framework_catalog_revisions` exists yet.
            // `getAttribute()`, not `$competency->revision_id`: the class
            // docblock's `@property int $revision_id` describes a HYDRATED
            // row (accurate — the column is NOT NULL), but mid-`creating()`
            // an unsaved instance genuinely can still be null; the magic
            // property access would make PHPStan treat this check as
            // statically impossible and report it as always-false.
            if ($competency->getAttribute('revision_id') === null && Schema::hasColumn('framework_competencies', 'revision_id')) {
                $competency->revision_id = FrameworkCatalogRevision::where('is_baseline', true)->value('id');
            }
        });
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
     * Roles that include this competency.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'framework_role_competency');
    }
}
