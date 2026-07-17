<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * Global Role model (C3 Framework Catalog).
 *
 * GLOBAL — NOT tenant-scoped. Roles are shared across all organizations.
 * Extends plain Model (NOT TenantModel).
 *
 * @property string $code
 * @property string $name  (resolved via current locale)
 * @property string $responsibilities  (resolved via current locale)
 */
class Role extends Model
{
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
    protected $fillable = ['code', 'name', 'responsibilities'];

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
