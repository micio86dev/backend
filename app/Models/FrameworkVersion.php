<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\LockedFrameworkVersionException;
use Database\Factories\FrameworkVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property string $version
 * @property bool $is_locked
 * @property string|null $label
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
    protected $fillable = ['organization_id', 'version', 'label'];

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
        });
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
