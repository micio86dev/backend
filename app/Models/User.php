<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * User model (C2).
 *
 * Implements JWTSubject for jwt-auth.
 * Uses HasRoles (Spatie) for org-scoped RBAC (teams mode, team_id = organization_id).
 *
 * SECURITY INVARIANTS:
 * - `organization_id` MUST NOT be in $fillable — set only by trusted service code.
 * - `is_superadmin` MUST NOT be in $fillable — prevents mass-assignment privilege escalation.
 *   A crafted payload with `is_superadmin=true` MUST NOT persist (DB default=false is not
 *   sufficient because fillable mass-assignment sets the value before INSERT).
 * - `deactivated_at` MUST NOT be in $fillable — written only by
 *   UserController::deactivate/activate (backoffice-missing-pages D5).
 * - `password_changed_at` MUST NOT be in $fillable — written only by
 *   ProfileController::updatePassword (user-profile-self-service, design D3).
 *   Compared against a JWT's `iat` claim by RejectStaleCredentials to revoke
 *   every session other than the one that performed the change.
 *
 * JWT claims:
 * - getJWTCustomClaims() includes organization_id for CLIENT CONVENIENCE only.
 *   The server NEVER trusts this claim for scoping — TenantContext reads exclusively
 *   from $user->organization_id (DB record).
 *
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $password_changed_at
 */
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Mass-assignable attributes.
     * organization_id and is_superadmin are intentionally excluded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        // C12 D7 — the recipient's preferred notification language. A
        // preference, not a security attribute, so mass assignment is
        // appropriate here in a way it is not for organization_id.
        'locale',
    ];

    /**
     * Hidden attributes (never serialized to arrays/JSON).
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The organization this user belongs to (nullable for platform superadmins).
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whether this user is soft-deactivated (backoffice-missing-pages D5).
     *
     * `deactivated_at` is deliberately NOT in $fillable — it is written only by
     * UserController::deactivate/activate, never by a PATCH mass-update.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    // -------------------------------------------------------------------------
    // JWTSubject
    // -------------------------------------------------------------------------

    /**
     * Get the identifier used as the JWT subject claim.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return custom JWT claims to include in the payload.
     *
     * INFORMATIONAL ONLY — the server must never use organization_id from the JWT
     * claim for any authorization or scoping decision. TenantContext reads from the
     * DB record ($user->organization_id) exclusively.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'organization_id' => $this->organization_id,
        ];
    }

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
            'deactivated_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }
}
