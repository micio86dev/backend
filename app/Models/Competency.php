<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompetencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * Global Competency model (C3 Framework Catalog).
 *
 * GLOBAL — NOT tenant-scoped. Competencies are shared across all organizations.
 * type: 'standard' (18 seeded competencies) | 'potential' (MTG/LAT — pending authoring).
 *
 * @property string $code
 * @property string $name (resolved via current locale)
 * @property string $definition (resolved via current locale)
 * @property string $type standard|potential
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
    protected $fillable = ['code', 'name', 'definition', 'type'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => 'string',
    ];

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
