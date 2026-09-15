<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\PublishedRevisionImmutableException;
use Carbon\CarbonImmutable;
use Database\Factories\FrameworkCatalogRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global FrameworkCatalogRevision model (framework-catalogue-authoring PR1, D1).
 *
 * GLOBAL — NOT tenant-scoped. A revision is the platform-wide unit of
 * catalogue content: every catalogue-content table (`framework_roles`,
 * `framework_competencies`, `framework_bars_indicators`,
 * `framework_role_competency`, `framework_default_questions`) points at one
 * via `revision_id`, and `FrameworkVersion.revision_id` resolves to one.
 *
 * `state`: `draft` (mutable) or `published` (immutable, no exceptions — D2).
 * `is_baseline`: the ONE revision the seeder owns (partial unique index
 * enforces "at most one"). A second partial unique index enforces "at most
 * one open draft at a time".
 *
 * @property int $id
 * @property string $state draft|published
 * @property bool $is_baseline
 * @property string|null $label
 * @property CarbonImmutable|null $published_at `immutable_datetime`, so the
 *                                              hydrated class is `CarbonImmutable` — NOT the mutable `Carbon`
 *                                              this annotation used to name. Different class, not a subtype, and
 *                                              precisely the Larastan inference mismatch PHPStan at max level is
 *                                              kept at a zero-error baseline to catch.
 * @property int|null $published_by_user_id
 */
class FrameworkCatalogRevision extends Model
{
    /** @use HasFactory<FrameworkCatalogRevisionFactory> */
    use HasFactory;

    protected $table = 'framework_catalog_revisions';

    /**
     * @var list<string>
     */
    protected $fillable = ['state', 'is_baseline', 'label', 'published_at', 'published_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_baseline' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * Register the immutability guard on boot (framework-catalogue-authoring
     * PR1, D2). Mirrors `FrameworkVersion::booted()`'s `is_locked` guard
     * shape exactly: once `state` was `published`, ANY further mutation to
     * this row is refused — "published, immutable, no exceptions" is a
     * property of the DATA, not of which code path wrote it.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $revision): void {
            if ($revision->getOriginal('state') === 'published') {
                throw new PublishedRevisionImmutableException(
                    "FrameworkCatalogRevision [{$revision->id}] is published and cannot be mutated."
                );
            }
        });

        // DELETING TOO, and the omission was not cosmetic. The docblock above
        // claimed an exact mirror of `FrameworkVersion::booted()` while
        // registering one of its two listeners, so `$published->delete()` went
        // straight through. The `restrictOnDelete` foreign keys look like they
        // cover this, and they do not: they refuse only while content still
        // points at the revision, so a revision published before its content
        // was attached — or published and then emptied — was deletable by any
        // Eloquent path. "Immutable, no exceptions" and "deletable while
        // nothing happens to reference it" are not the same sentence.
        static::deleting(function (self $revision): void {
            if ($revision->getOriginal('state') === 'published') {
                throw new PublishedRevisionImmutableException(
                    "FrameworkCatalogRevision [{$revision->id}] is published and cannot be deleted."
                );
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'revision_id');
    }

    /**
     * @return HasMany<Competency, $this>
     */
    public function competencies(): HasMany
    {
        return $this->hasMany(Competency::class, 'revision_id');
    }

    /**
     * @return HasMany<BarsIndicator, $this>
     */
    public function barsIndicators(): HasMany
    {
        return $this->hasMany(BarsIndicator::class, 'revision_id');
    }

    /**
     * @return HasMany<FrameworkDefaultQuestion, $this>
     */
    public function defaultQuestions(): HasMany
    {
        return $this->hasMany(FrameworkDefaultQuestion::class, 'revision_id');
    }
}
