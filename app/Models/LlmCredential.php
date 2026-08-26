<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * An organization's own bring-your-own Google Gemini key
 * (pluggable-conversation-llm PR P2, design D2).
 *
 * `api_key` follows `Project.php:92,103`'s double convention EXACTLY: cast
 * `'encrypted'` (protects the database) AND `$hidden` (protects the
 * serializer). Both are required — the cast alone still lets `toArray()`
 * emit the plaintext, and this key is POSTed to Tavus on every PAL PATCH, so
 * it travels through more code paths than `webhook_secret` ever has.
 *
 * `organization_id` is NOT fillable — stamped by `TenantScoped` from the
 * resolver, never from a payload, same invariant every other TenantModel
 * carries.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $vendor
 * @property string $api_key
 * @property string $key_last_four
 * @property string $key_fingerprint
 * @property string|null $heygen_secret_id
 * @property Carbon|null $validated_at
 * @property string|null $validation_error
 */
class LlmCredential extends TenantModel
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

    /** @var array<string, string> */
    protected $casts = [
        // Protects the database. $hidden below protects the serializer. Both
        // are required — see the class docblock.
        'api_key' => 'encrypted',
        'validated_at' => 'datetime',
    ];

    /** @var list<string> */
    protected $hidden = ['api_key'];
}
