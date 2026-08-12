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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Organization $organization */
        $organization = $this->resource;

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
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
