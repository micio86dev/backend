<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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
        // Per-organization branding. Both NULLABLE permanently: an organization
        // that configures neither renders in the Quint palette DESIGN.md
        // defines, so null is a real state rather than an unfilled one.
        //
        // `logo_path` is a PATH on the configured disk, never a URL — the disk
        // differs per environment (`local` in development, `s3` in production),
        // so a stored URL would bake one environment's host into the row.
        'logo_path',
        'primary_color',
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
     * The logo as an ABSOLUTE url, or null when none is configured.
     *
     * Separate from the relative `Storage::url()` the API resources return,
     * and deliberately so rather than by oversight: a resource is read by an
     * app that HAS an origin and can resolve a relative path against it. An
     * EMAIL has none, so `/storage/logos/acme.png` is a broken image in every
     * client — which is precisely the failure the original "no logo in mail"
     * ruling predicted, arriving through a different door.
     *
     * Anchored on `APP_URL` because that is the same source
     * `AppServiceProvider::forcePublicRootUrl()` already uses to build public
     * URLs, so mail and the rest of the application cannot disagree about
     * where this deployment lives.
     */
    public function absoluteLogoUrl(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        $url = Storage::url($this->logo_path);

        // Already absolute on an S3-style disk; only the local disk returns a
        // rooted path that needs anchoring.
        if (preg_match('#\Ahttps?://#i', $url) === 1) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
