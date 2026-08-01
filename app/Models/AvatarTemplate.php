<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A named avatar/voice configuration an operator can activate (C14).
 *
 * Exactly one template per organization may be active at a time, and that is
 * enforced by a partial unique index — `avatar_templates_one_active_per_org` —
 * rather than by this class. Two concurrent activations both read "nobody else
 * is active", both write, and both win; a check that only holds when nobody is
 * in a hurry is not a check. Application code deactivates first for a clean
 * user experience, and the index is what makes the invariant true.
 *
 * `organization_id` is NOT fillable. It is stamped by TenantScoped from the
 * resolver, never from a payload — the same invariant Participant and User
 * carry, for the same reason.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property string $provider
 * @property array<string, mixed> $config
 * @property bool $is_active
 */
class AvatarTemplate extends TenantModel
{
    /**
     * Mirrors the database defaults IN MEMORY.
     *
     * Without this a freshly created instance carries `is_active = null` until
     * something reloads it — so `if ($template->is_active)` reads false for the
     * right answer and the wrong reason, and the day the column default changes
     * nothing here notices.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
        'config' => '{}',
    ];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'provider',
        'config',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Without this the column round-trips as a JSON STRING, and every
            // downstream read sees "no avatar configured" — a failure that
            // reads as a missing setting rather than a broken cast.
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
