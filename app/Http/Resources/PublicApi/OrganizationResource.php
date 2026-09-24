<?php

declare(strict_types=1);

namespace App\Http\Resources\PublicApi;

use App\Enums\ApiKeyMode;
use App\Models\Organization;
use App\PublicApi\Serializers\OrganizationSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin HTTP wrapper around `App\PublicApi\Serializers\OrganizationSerializer`
 * for `GET /v1/organization` (public-api step 4).
 *
 * @mixin Organization
 */
final class OrganizationResource extends JsonResource
{
    /**
     * The contract's `getOrganization` response body IS the `Organization`
     * schema directly — no `data` envelope (unlike the paginated list
     * endpoints, which build their own `{data, next_cursor, has_more}`
     * shape). `JsonResource` wraps a single resource in `data` by default;
     * this disables it for this class only, rather than globally.
     *
     * @var string|null
     */
    public static $wrap = null;

    public function __construct(Organization $resource, private readonly ApiKeyMode $mode)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: string, name: string, mode: 'live'|'test', allowed_domains: list<string>, created_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var Organization $organization */
        $organization = $this->resource;

        return OrganizationSerializer::toArray($organization, $this->mode);
    }
}
