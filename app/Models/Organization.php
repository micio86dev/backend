<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $default_webhook_url
 * @property list<string>|null $default_webhook_events
 * @property string|null $default_webhook_secret
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        // Org-level webhook defaults (backoffice-missing-pages D1/D3) — copy-on-create
        // prefill for new Projects. `default_webhook_secret` is never serialized
        // (see $hidden below), mirroring Project::$fillable/$hidden for webhook_secret.
        'default_webhook_url',
        'default_webhook_secret',
        'default_webhook_events',
    ];

    /**
     * default_webhook_secret is excluded from all serialized output — same
     * discipline as Project::$hidden for webhook_secret.
     *
     * @var list<string>
     */
    protected $hidden = ['default_webhook_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // encrypted at rest; also in $hidden to prevent serialization exposure.
            'default_webhook_secret' => 'encrypted',
            'default_webhook_events' => 'array',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
