<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * BARS Indicator model (C3 Framework Catalog).
 *
 * GLOBAL — NOT tenant-scoped. BARS indicators are shared across all organizations.
 * Stores per-role, per-competency behavioral indicators with reference anchors {5,3,1}.
 *
 * BARS JSON→column mapping:
 *   indicator  → text
 *   scale.5    → anchor_5
 *   scale.3    → anchor_3
 *   scale.1    → anchor_1
 *   array index → position (0-based, stable JSON order)
 *
 * translation_gap: true when any of the four translatable fields lacks an IT authoring translation.
 * Detected via hasTranslation('field', 'it') — NOT by testing empty value.
 *
 * @property int    $role_id
 * @property int    $competency_id
 * @property string $text       (resolved via current locale)
 * @property string $anchor_5   (resolved via current locale)
 * @property string $anchor_3   (resolved via current locale)
 * @property string $anchor_1   (resolved via current locale)
 * @property int    $position
 */
class BarsIndicator extends Model
{
    use HasTranslations;

    /**
     * Custom table name — prefixed for consistency with the framework catalog domain.
     */
    protected $table = 'framework_bars_indicators';

    /**
     * @var list<string>
     */
    public array $translatable = ['text', 'anchor_5', 'anchor_3', 'anchor_1'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'competency_id',
        'text',
        'anchor_5',
        'anchor_3',
        'anchor_1',
        'position',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Competency, $this>
     */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /**
     * Whether any of the four translatable fields is missing an IT authoring translation.
     * This is an authoring-completeness signal — independent of the request's locale.
     */
    public function hasTranslationGap(): bool
    {
        return ! $this->hasTranslation('text', 'it')
            || ! $this->hasTranslation('anchor_5', 'it')
            || ! $this->hasTranslation('anchor_3', 'it')
            || ! $this->hasTranslation('anchor_1', 'it');
    }
}
