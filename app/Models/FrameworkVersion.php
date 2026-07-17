<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Tenant-scoped FrameworkVersion model (C3 Framework Catalog).
 *
 * Extends TenantModel (C2) — automatically scoped by organization_id.
 * In C3 this is a WORKING DRAFT label; immutability is activated by C4 (snapshot-at-pin).
 * is_locked: forward-looking guard. C4 sets it to true on pin — C3 ships the guard.
 *
 * immutabilityGuard: blocks BOTH deletion AND mutation when is_locked=true.
 * This is a model-level guard (not a DB constraint). C4 activates it.
 *
 * @property string $version
 * @property bool   $is_locked
 * @property string|null $label
 */
class FrameworkVersion extends TenantModel
{
    /**
     * @var list<string>
     */
    protected $fillable = ['organization_id', 'version', 'is_locked', 'label'];

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
     * C4 sets is_locked=true; before that, this guard is a no-op.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::deleting(function (self $fv): void {
            if ($fv->is_locked) {
                throw new RuntimeException(
                    "FrameworkVersion [{$fv->id}] is locked and cannot be deleted."
                );
            }
        });

        static::updating(function (self $fv): void {
            if ($fv->getOriginal('is_locked') === true) {
                throw new RuntimeException(
                    "FrameworkVersion [{$fv->id}] is locked and cannot be mutated."
                );
            }
        });
    }

    /**
     * Placeholder for future C4 Project relationship.
     * C4 wires the project → framework_version_id FK.
     *
     * @return HasMany<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function projects(): HasMany
    {
        // C4 will replace this with: return $this->hasMany(Project::class);
        return $this->hasMany(self::class, 'id', 'id')->whereRaw('1=0'); // @phpstan-ignore-line
    }
}
