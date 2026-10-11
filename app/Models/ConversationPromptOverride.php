<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operator-authored text for one competency, optionally narrowed to one role
 * (db-driven-conversation-prompts PR5, design N-3).
 *
 * Keyed by `role_code` / `competency_code`, never by foreign key: catalogue
 * roles and competencies are cloned with new ids on every catalogue revision.
 * A null `role_code` applies to every role. GLOBAL, NOT tenant-scoped, and
 * immutable by database trigger (`created_at` only).
 *
 * @property int $id
 * @property int $prompt_set_id
 * @property string|null $role_code
 * @property string $competency_code
 * @property string $locale
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property-read ConversationPromptSet $promptSet
 */
class ConversationPromptOverride extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['prompt_set_id', 'role_code', 'competency_code', 'locale', 'body'];

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
