<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only record of one admin/operator mutation (C13).
 *
 * `organization_id` is deliberately NOT fillable — stamped by TenantScoped,
 * same as every other tenant model. Mass-assigning the tenant key is the whole
 * hazard the arch test exists for.
 *
 * NULLABLE since framework-catalogue-authoring PR8 (design D13): NULL means
 * PLATFORM scope, a mutation with no acting tenant (a superadmin editing the
 * shared catalogue). `TenantScoped`'s global scope filters
 * `organization_id = X`, which SQL never matches against NULL — a platform
 * row is invisible to every tenant-scoped read by construction, with no
 * reader-side code change required. The only writer of a NULL-org row is
 * `App\Support\Superadmin\PlatformAuditWriter`, via `DB::table()` — never
 * through this model, whose `TenantScoped::creating` listener still
 * unconditionally stamps the ambient tenant and throws when none is
 * resolved, exactly as before.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
class AuditLog extends TenantModel
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * Append-only: only `created_at` exists, set by the DB default. There is no
     * `updated_at` column and Eloquent must never try to maintain one.
     */
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before',
        'after',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
