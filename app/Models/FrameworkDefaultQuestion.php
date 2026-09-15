<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property int $revision_id
 * @property int $competency_id
 * @property string $text (resolved via current locale)
 * @property int $position
 */
class FrameworkDefaultQuestion extends Model
{
    /** @use HasFactory<FrameworkDefaultQuestionFactory> */
    use HasFactory;

    use HasTranslations;

    protected $table = 'framework_default_questions';

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
