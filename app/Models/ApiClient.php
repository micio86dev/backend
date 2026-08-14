<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ApiClientFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ApiClient model (C5 — M2M API Authentication).
 *
 * Represents an external machine-to-machine caller.
 *
 * Security invariants:
 * - Does NOT extend Foundation\Auth\User — importing Authorizable::can() would
 *   clash with the custom flat-array can() helper defined here.
 * - Does NOT use HasRoles — M2M clients have no Spatie roles; calling hasRole()
 *   would throw BadMethodCallException.
 * - key_hash MUST NOT be in $fillable — set only by ApiClientController::store().
 * - key_hash MUST be in $hidden — never serialized to JSON responses.
 * - abilities is cast to array — stored as jsonb, flat lowercase-canonical strings.
 * - NOT a TenantModel — the guard queries raw/unscoped because TenantResolver is
 *   not stamped yet at guard-resolution time.
 *
 * REQ-1, REQ-3, REQ-6 / design §ApiClient
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $key_hash
 * @property string[]|null $abilities
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ApiClient extends Model implements AuthenticatableContract
{
    /** @use HasFactory<ApiClientFactory> */
    use Authenticatable, HasFactory;

    /**
     * Mass-assignable attributes.
     * key_hash is intentionally excluded — set only by trusted service code.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'abilities',
        'is_active',
        'expires_at',
        'last_used_at',
    ];

    /**
     * Hidden attributes — never serialized to arrays/JSON.
     *
     * @var list<string>
     */
    protected $hidden = [
        'key_hash',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope: active clients only (is_active=true AND not expired).
     *
     * Used by the guard for raw unscoped lookups.
     * Carbon now() is used (not raw SQL NOW()) for consistent UTC handling.
     *
     * @param  Builder<ApiClient>  $query
     * @return Builder<ApiClient>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now())
            );
    }

    /**
     * Three-state derivation of this client's status, in PHP, from the SAME
     * predicate `scopeActive` expresses in SQL: `is_active = true AND
     * (expires_at IS NULL OR expires_at > now())`.
     *
     * A SQL `WHERE` and a PHP boolean cannot literally share code, so this is
     * a second expression of the one rule, not a third source of truth —
     * pinned against `scopeActive` by `ApiClientStateTest`'s five-row
     * equivalence table (design.md D3). Server-derived deliberately: the
     * guard that accepts or refuses the key compares `expires_at` against
     * the SERVER's clock, so the badge must use the same clock, not the
     * viewer's browser.
     *
     * @return 'active'|'expired'|'revoked'
     */
    public function state(): string
    {
        if (! $this->is_active) {
            return 'revoked';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    // -------------------------------------------------------------------------
    // Ability check
    // -------------------------------------------------------------------------

    /**
     * Check whether this client has the given ability.
     *
     * Strict in_array — canonical lowercase only. This is the custom M2M ability
     * helper, entirely separate from Spatie's HasRoles::can().
     *
     * @param  string  $ability  Canonical lowercase ability name.
     */
    public function can(string $ability, mixed $arguments = []): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The organization this client belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
