<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BumpsRevisionContentVersion;
use Database\Factories\FrameworkDefaultQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * Global FrameworkDefaultQuestion model (framework-catalogue-authoring PR1,
 * catalogue-authoring spec — "Catalogue-Level Default Questions Per
 * Competency").
 *
 * GLOBAL — NOT tenant-scoped, revision-scoped like every other catalogue
 * table. A template a superadmin authors per competency;
 * `ApplyCompetencySelection` (D10, PR5) copies from it when a project first
 * selects the competency. Never read directly at interview time — only
 * `project_questions` (the per-project copy) is.
 *
 * `BumpsRevisionContentVersion` (framework-catalogue-authoring PR4, closing
 * a gap the PR3b machinery left open): `DiscardUnusedDraftRevision` treats
 * `content_version === 0` as "genuinely untouched, safe to discard". Without
 * this trait here, a superadmin who authored ONLY a default question in a
 * freshly-opened draft (no role/competency/indicator edit) would leave
 * `content_version` at 0, and an unrelated later request's failed
 * validation could discard that draft — deleting real, saved work.
 *
 * @property int $revision_id
 * @property int $competency_id
 * @property string $text (resolved via current locale)
 * @property int $position
 */
class FrameworkDefaultQuestion extends Model
{
    use BumpsRevisionContentVersion;

    /** @use HasFactory<FrameworkDefaultQuestionFactory> */
    use HasFactory;

    use HasTranslations;

    protected $table = 'framework_default_questions';

    /**
     * `BumpsRevisionContentVersion` (see class docblock) — mirrors
     * `BarsIndicator::booted()`'s own minimal registration.
     */
    protected static function booted(): void
    {
        static::bumpRevisionContentVersionListeners();
    }

    /**
     * @var list<string>
     */
    public array $translatable = ['text'];

    /**
     * @var list<string>
     */
    protected $fillable = ['revision_id', 'competency_id', 'text', 'position'];

    /**
     * @return BelongsTo<FrameworkCatalogRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(FrameworkCatalogRevision::class, 'revision_id');
    }

    /**
     * @return BelongsTo<Competency, $this>
     */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
