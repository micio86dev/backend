<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OrganizationResource (backoffice-missing-pages D2/D3).
 *
 * Serializes the singular /api/organization resource. `slug` is present but
 * read-only (never accepted by UpdateOrganizationRequest). `default_webhook_secret`
 * is NEVER serialized — it is $hidden + encrypted on the model (Organization.php),
 * and this resource exposes only a boolean presence flag, mirroring
 * ProjectResource's discipline for webhook_secret.
 *
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * Same Scramble local-assignment defect as `ApiClientResource` (design.md
     * D1) — a local var-annotated assignment alone is not honoured by
     * Scramble's static walk. `@scramble-return` is the actual override
     * hook; `@return` is kept for PHPStan/IDE tooling. `id` is backed by an
     * explicit `(int)` cast — `Project.php:86-88` records that pdo_pgsql can
     * return bigint as string, and the contract must be true regardless of
     * that PDO detail.
     *
     * @return array{id: int, name: string, slug: string, primary_color: string|null, logo_url: string|null, default_webhook_url: string|null, default_webhook_events: list<string>|null, has_default_webhook_secret: bool, created_at: string|null, updated_at: string|null}
     *
     * @scramble-return array{id: int, name: string, slug: string, primary_color: string|null, logo_url: string|null, default_webhook_url: string|null, default_webhook_events: list<string>|null, has_default_webhook_secret: bool, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var Organization $organization */
        $organization = $this->resource;

        return [
            'id' => (int) $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            // Branding. Both keys are ALWAYS present, null included: a UI
            // cannot tell "not configured" from "this build of the API has no
            // branding" when a field is simply missing, and it needs that
            // distinction to decide between the organization's colour and the
            // product's own.
            'primary_color' => $organization->primary_color,
            // A resolved URL, not the stored path. The path is disk-relative
            // and the disk differs per environment, so resolving it here is the
            // one place that knows which disk is configured — the same reason
            // `logo_path` is not accepted on the update endpoint.
            //
            // ABSOLUTE, via the model's own accessor, which resolves through
            // the single storage configuration point with NO disk argument —
            // naming the disk here is what `SingleStorageDiskArchTest`
            // forbids, and it caught both attempts. That test greps the
            // SOURCE, so the forbidden expression must not appear even in
            // prose.
            //
            // Absolute rather than the rooted path this returned until
            // image-upload-crop-field: the local disk yields `/storage/...`,
            // and the backoffice is a different origin from this API, so the
            // browser resolved that against the BACKOFFICE and 404'd. The
            // file was stored correctly the whole time. An S3-style disk
            // already answers absolutely and passes through untouched.
            'logo_url' => $organization->absoluteLogoUrl(),
            'default_webhook_url' => $organization->default_webhook_url,
            'default_webhook_events' => $organization->default_webhook_events,
            // default_webhook_secret intentionally excluded (hidden + encrypted) —
            // only a presence boolean is exposed, never the value.
            'has_default_webhook_secret' => $organization->default_webhook_secret !== null,
            'created_at' => $organization->created_at?->toISOString(),
            'updated_at' => $organization->updated_at?->toISOString(),
        ];
    }
}
