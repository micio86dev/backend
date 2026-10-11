<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One template fragment of a prompt set, per (key, locale)
 * (db-driven-conversation-prompts PR5, design N-3).
 *
 * GLOBAL, NOT tenant-scoped (see `ConversationPromptSet`). Immutable by
 * database trigger, so the table carries `created_at` only and the model has
 * no `updated_at`.
 *
 * @property int $id
 * @property int $prompt_set_id
 * @property string $fragment_key
 * @property string $locale
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property-read ConversationPromptSet $promptSet
 */
class ConversationPromptFragment extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['prompt_set_id', 'fragment_key', 'locale', 'body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ConversationPromptSet, $this> */
    public function promptSet(): BelongsTo
    {
        return $this->belongsTo(ConversationPromptSet::class, 'prompt_set_id');
    }
}
