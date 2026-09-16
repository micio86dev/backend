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
 * @property CarbonImmutable|null $published_at `immutable_datetime` cast, so
 *                                              the hydrated class is `CarbonImmutable` — NOT the mutable `Carbon`.
 *                                              Larastan infers the cast's return type at max level, so an
 *                                              annotation naming the wrong class is a real PHPStan error, not a
 *                                              cosmetic one.
 * @property int|null $published_by_user_id
 * @property int|null $parent_revision_id the published revision this one was
 *                                        cloned from (`OpenDraftRevision`) — `null` for the baseline and
 *                                        for any revision that predates this column (gga review finding:
 *                                        every other fillable column was annotated here except this one).
 * @property int $content_version bumped by `BumpsRevisionContentVersion` on
 *                                every Eloquent write to a catalogue-content model — read by
 *                                `DiscardUnusedDraftRevision` to tell a genuinely untouched clone
 *                                apart from one a concurrent request already wrote into. NOT
 *                                `$fillable`: only ever changed via `increment()`.
 */
class FrameworkCatalogRevision extends Model
{
    /** @use HasFactory<FrameworkCatalogRevisionFactory> */
    use HasFactory;

    protected $table = 'framework_catalog_revisions';

    /**
     * @var list<string>
     */
    protected $fillable = ['state', 'is_baseline', 'label', 'published_at', 'published_by_user_id', 'parent_revision_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_baseline' => 'boolean',
            'published_at' => 'immutable_datetime',
            // gga review finding (low): DiscardUnusedDraftRevision compares
            // this with a STRICT `!== 0` — correct today on PHP 8.5 +
            // pdo_pgsql, which already hydrates an integer column as
            // native `int`, but an uncast attribute is the failure mode
            // where that guard silently degrades into a permanent no-op
            // with no error anywhere the moment that stops being true.
            'content_version' => 'integer',
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
     * The published revision this one was cloned from (framework-catalogue-
     * authoring PR3) — `null` for the baseline (the root) and for any
     * revision that predates this column. `PublishRevision`'s cross-role
     * duplicate DELTA check diffs against this.
     *
     * @return BelongsTo<FrameworkCatalogRevision, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    /**
     * THE open draft, if any (framework-catalogue-authoring PR3b, H10) —
     * `null` when none is open. `framework_catalog_revisions_one_draft`
     * guarantees at most one exists, which is exactly what makes a single
     * static lookup meaningful rather than arbitrary. Replaces the identical
     * `FrameworkCatalogRevision::where('state', 'draft')->first()` that was
     * repeated verbatim across `Role`/`Competency`/`BarsIndicator`/
     * `RevisionController` (`index`/`update`/`destroy`/`current`/`publish`)
     * and `ResolvesOpenDraftRevision::existingOpenDraftRevisionId()` — one
     * query, named once, never re-derived per call site.
     */
    public static function openDraft(): ?self
    {
        return static::where('state', 'draft')->first();
    }

    /**
     * The newest PUBLISHED revision, or `null` when none exists yet
     * (framework-catalogue-authoring PR4b, K7) — never a draft. Mirrors
     * `openDraft()`'s own rationale: replaces the identical
     * `FrameworkCatalogRevision::where('state', 'published')
     * ->orderByDesc('published_at')->orderByDesc('id')->first()` (or its
     * `->value('id')` shape) that was independently re-derived across
     * `OpenDraftRevision::open()`, `CatalogueExportCommand::resolveRevision()`,
     * `FrameworkVersion::assignLatestPublishedRevisionIfUnset()`, and
     * `CatalogueRevisionResolver::tryLatestPublished()` — one query, named
     * once, so "latest" means the SAME thing everywhere it is asked.
     */
    public static function latestPublished(): ?self
    {
        return static::where('state', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
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
