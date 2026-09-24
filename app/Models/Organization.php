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
 * @property string|null $logo_path
 * @property string|null $primary_color
 * @property int|null $public_api_rate_limit_live
 * @property int|null $public_api_rate_limit_test
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
        // Public API (`/v1`) rate-limit overrides (public-api step 3, SPEC.md
        // §3.2) — both null-by-default; see the migration's own docblock for
        // why they stay null rather than carrying a literal default.
        'public_api_rate_limit_live',
        'public_api_rate_limit_test',
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
     * The single source for every reader — `OrganizationResource`,
     * `ParticipantResource` and `EmailBranding` all call this and nothing
     * builds a logo URL of its own. Absolute rather than relative because an
     * EMAIL has no origin to resolve a path against, so `/storage/acme.png`
     * is a broken image in every client on earth; the two Nuxt apps are
     * likewise separate origins from this API.
     *
     * Anchored on `APP_URL` — via `AppServiceProvider::forcePublicRootUrl()`,
     * which `route()` honours — because that is the same source public URLs
     * are already built from, so mail and the rest of the application cannot
     * disagree about where this deployment lives.
     *
     * THIS API'S OWN ROUTE, NOT `Storage::url()`, and that is the whole point.
     * The previous version returned the disk's URL and anchored it on
     * `APP_URL` only when it came back relative — correct for the `local`
     * disk, and a no-op for `s3`, where `Storage::url()` is ALREADY absolute:
     * `AWS_ENDPOINT` + `/bucket/` + key. That host is the S3 API endpoint of a
     * private bucket, so every such URL answered 401 to the browser and the
     * logo silently never painted. The bucket cannot be opened up to repair
     * it: it is the same bucket that holds candidate proctoring snapshots.
     *
     * The route is STABLE — it names the organization, never the stored
     * object — which is what lets the same string sit in an email for days
     * (`EmailBranding`) and still resolve, and what makes a replaced logo take
     * effect in already-sent messages instead of breaking them. The
     * short-lived signature lives behind the redirect, where nothing has to
     * remember it.
     *
     * The QUERY STRING is not stable, and that is deliberate: it is a
     * cache-buster, `?v=<the stored object's own filename>`. `show()` answers
     * this route with `Cache-Control: public, max-age=<redirect_cache_seconds>`
     * (up to ten minutes) — a stable path with no version signal, so an admin
     * who replaces the logo and reloads the very settings page that just
     * requested it keeps seeing the FILE THAT UPLOAD REPLACED, because the
     * browser serves its cached redirect rather than asking again. `store()`
     * mints a fresh UUID filename on every upload, so this value changes
     * exactly when, and only when, the logo does. `show()` never reads the
     * query string — it resolves `logo_path` fresh from the database — so an
     * old email or an already-open candidate tab still resolves to whatever
     * logo is current today; only a NEW request for this URL sees a fresh
     * string to fetch.
     */
    public function absoluteLogoUrl(): ?string
    {
        $key = $this->logo_path;

        if ($key === null) {
            return null;
        }

        // The PATH from the route table, the HOST from `app.url`, rather than
        // one absolute `route()` call. `route()` absolute would be anchored on
        // whatever `AppServiceProvider::forcePublicRootUrl()` forced at boot —
        // and that method returns early when `APP_URL` is unset or malformed,
        // leaving the generator on the request's own Host. This API is always
        // reached through something else (the Nuxt dev proxies, Railway's
        // edge), so that Host is the INTERNAL one: the exact
        // `http://api:8000/...` that no browser can resolve, which that same
        // docblock records having already shipped once. Naming the source here
        // makes the logo URL independent of boot order and of a route table
        // that has not been forced.
        $path = route('organizations.logo', ['organization' => $this->getKey()], absolute: false);

        $version = basename($key);

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/').'?v='.rawurlencode($version);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
