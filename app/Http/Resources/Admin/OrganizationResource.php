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
     * @return array{id: int, name: string, slug: string, default_webhook_url: string|null, default_webhook_events: list<string>|null, has_default_webhook_secret: bool, created_at: string|null, updated_at: string|null}
     *
     * @scramble-return array{id: int, name: string, slug: string, default_webhook_url: string|null, default_webhook_events: list<string>|null, has_default_webhook_secret: bool, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var Organization $organization */
        $organization = $this->resource;

        return [
            'id' => (int) $organization->id,
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
