<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A published, immutable set of conversation prompt text
 * (db-driven-conversation-prompts PR5, design N-3).
 *
 * GLOBAL, NOT tenant-scoped — extends `Model` directly, never `TenantModel`,
 * and joins the exclusion list in `tests/Arch/C2/TenantModelArchTest.php`.
 * A prompt set is a platform artefact shared by every organization.
 *
 * Immutable by database trigger: only `is_active`, `activated_at` and
 * `updated_at` may change after INSERT, which is why the activation columns
 * are not mass assignable — they are written by the activation action alone.
 * `content_sha256` seals the fragments and overrides and is computed before
 * the INSERT.
 *
 * @property int $id
 * @property string $label
 * @property string $content_sha256
 * @property bool $is_active
 * @property CarbonImmutable|null $activated_at
 * @property string|null $notes
 */
class ConversationPromptSet extends Model
{
    /** @var list<string> */
    protected $fillable = ['label', 'content_sha256', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'activated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ConversationPromptFragment, $this> */
    public function fragments(): HasMany
    {
        return $this->hasMany(ConversationPromptFragment::class, 'prompt_set_id');
    }

    /** @return HasMany<ConversationPromptOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(ConversationPromptOverride::class, 'prompt_set_id');
    }
}
