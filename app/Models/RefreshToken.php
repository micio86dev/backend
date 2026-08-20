<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One generation of an operator refresh-token rotation chain
 * (backoffice-session-refresh-hardening, storage corrected from Redis to
 * PostgreSQL — see design.md). NOT tenant-scoped: App\Support\Auth\RefreshTokenStore
 * is reached from POST /api/auth/refresh, which runs OUTSIDE auth:api and
 * therefore before any tenant context is established.
 *
 * `token_hash` holds ONLY sha256(secret) — the raw secret is never persisted.
 * `consumed_at` vs `revoked_at` are deliberately distinct — see the migration's
 * class doc for the full reasoning behind that split.
 *
 * @property int $id
 * @property int $user_id
 * @property string $family_id
 * @property string $token_hash
 * @property int $generation
 * @property Carbon $absolute_expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RefreshToken extends Model
{
    use Prunable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'family_id',
        'token_hash',
        'generation',
        'absolute_expires_at',
        'consumed_at',
        'revoked_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'absolute_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Scheduled `model:prune` target (bootstrap/app.php's `withSchedule()`).
     * A row is dead forever, and therefore safe to delete, once its family's
     * absolute ceiling has passed OR it has been explicitly revoked
     * (reuse-kill, logout, or an earlier ceiling-expiry rotation) — neither
     * condition can ever become live again.
     *
     * @return Builder<RefreshToken>
     */
    public function prunable(): Builder
    {
        return static::where('absolute_expires_at', '<', now())
            ->orWhereNotNull('revoked_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
