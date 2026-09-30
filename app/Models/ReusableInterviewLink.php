<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\PublicApi\PubliclyIdentifiable;
use Database\Factories\ReusableInterviewLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A non-expiring, revocable entry link that many candidates can redeem
 * (reusable-interview-links, design AD-5).
 *
 * Extends `TenantModel`, so every read is scoped to the current organisation
 * and `organization_id` is stamped from the tenant context on create, never
 * from input. The one place that must find a link BEFORE a tenant is known, the
 * public redemption action, lifts the named `tenant` scope itself and then runs
 * inside the link's own tenant context; an architecture test pins that to that
 * single action.
 *
 * Security invariants:
 * - `token_hash` is in `$hidden` and NOT in `$fillable`: it is the lookup key
 *   of a live credential and must not reach a serialised response or be bound
 *   from a request body. The raw token is never stored at all.
 * - `$fillable` is `['label']` and nothing else. `organization_id`,
 *   `project_id`, `lang`, `token_prefix`, `uses_count`, the timestamps and the
 *   actor columns are set only through `forceFill()` by the trusted actions, so
 *   a body key of the same name is silently ignored.
 * - `public_id` is minted by `HasPublicId`; `PublicId::encode()` adds the
 *   `rlk_` prefix on the way out.
 *
 * Status is derived, not stored: a link is active exactly while `disabled_at`
 * is NULL (`scopeActive()`). There is no expiry and no re-enable.
 *
 * @property int $id
 * @property string $public_id BARE 26-char ULID — always exposed through
 *                             `App\Support\PublicApi\PublicId::encode()`, which prepends `rlk_`.
 * @property int $organization_id
 * @property int $project_id
 * @property int|null $created_by
 * @property string|null $label
 * @property string $lang
 * @property string $token_hash lowercase hex SHA-256 of the whole raw token (hidden)
 * @property string $token_prefix `beai_rl_` plus the first 8 random characters
 * @property int $uses_count
 * @property Carbon|null $last_used_at
 * @property Carbon|null $disabled_at
 * @property int|null $disabled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReusableInterviewLink extends TenantModel implements PubliclyIdentifiable
{
    /** @use HasFactory<ReusableInterviewLinkFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * The operator-facing name is the only attribute a request may set.
     *
     * @var list<string>
     */
    protected $fillable = [
        'label',
    ];

    /**
     * Never serialised: the lookup key of a live credential.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uses_count' => 'integer',
            'last_used_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public static function publicIdPrefix(): string
    {
        return 'rlk_';
    }

    /**
     * Scope: links that have not been disabled.
     *
     * @param  Builder<ReusableInterviewLink>  $query
     * @return Builder<ReusableInterviewLink>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The user who created the link; NULL once that user is deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who disabled the link; NULL while active or once that user is
     * deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function disabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    /**
     * The anonymous visitors this link produced, found through the nullable
     * `participants.reusable_interview_link_id` marker. Participants created any
     * other way carry NULL there and never appear.
     *
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'reusable_interview_link_id');
    }
}
