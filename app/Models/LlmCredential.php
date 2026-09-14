<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * BEAI's own Google Gemini key — a PLATFORM row (RATIFIED 2026-09-14).
 *
 * This reverses design D2 of pluggable-conversation-llm, which made it "an
 * organization's own bring-your-own key". It sits beside `LlmModel`, which was
 * already global on the same reasoning: the rate card is a vendor fact, and
 * the credential that pays for it is now BEAI's fact. Only a superadmin may
 * read or write these (`LlmCredentialPolicy`).
 *
 * Consequently NOT a `TenantModel`: there is no `organization_id` to scope by
 * and no tenant stamp on create. That is also why the cross-org comparisons
 * that used to guard the binding are gone from `AvatarTemplate::saving()` and
 * `LlmBindingResolver` — one key serving every tenant IS the design now, so a
 * check that refused exactly that had to go rather than be worked around.
 *
 * `api_key` follows `Project.php:92,103`'s double convention EXACTLY: cast
 * `'encrypted'` (protects the database) AND `$hidden` (protects the
 * serializer). Both are required — the cast alone still lets `toArray()`
 * emit the plaintext, and this key is POSTed to Tavus on every PAL PATCH, so
 * it travels through more code paths than `webhook_secret` ever has.
 *
 * @property int $id
 * @property string $name
 * @property string $vendor
 * @property string $api_key
 * @property string $key_last_four
 * @property string $key_fingerprint
 * @property string|null $heygen_secret_id
 * @property Carbon|null $validated_at
 * @property string|null $validation_error
 */
class LlmCredential extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'vendor',
        'api_key',
        'key_last_four',
        'key_fingerprint',
        'heygen_secret_id',
        'validated_at',
        'validation_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Protects the database. $hidden below protects the serializer.
            // Both are required — see the class docblock.
            'api_key' => 'encrypted',
            'validated_at' => 'datetime',
        ];
    }

    /** @var list<string> */
    protected $hidden = ['api_key'];
}
