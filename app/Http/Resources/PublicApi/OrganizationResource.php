<?php

declare(strict_types=1);

namespace App\Http\Resources\PublicApi;

use App\Enums\ApiKeyMode;
use App\Models\Organization;
use App\PublicApi\Serializers\OrganizationSerializer;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin HTTP wrapper around `App\PublicApi\Serializers\OrganizationSerializer`
 * for `GET /v1/organization` (public-api step 4).
 *
 * `#[SchemaName('PublicOrganization')]` (step 5 review follow-up, Part A):
 * this class shares the bare basename `OrganizationResource` with
 * `App\Http\Resources\Admin\OrganizationResource` — Scramble disambiguates
 * a basename collision by falling back to the FULLY-QUALIFIED class name
 * for one of the two, and which one wins was never a deliberate choice,
 * just registration order. When it fell the admin resource's way, the
 * backoffice's generated TS client — built against `components.schemas.
 * OrganizationResource` — broke 28 type usages. Naming this one
 * EXPLICITLY, distinctly, makes the admin schema keep its plain
 * `OrganizationResource` name unconditionally, collision or not.
 *
 * @mixin Organization
 */
#[SchemaName('PublicOrganization')]
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
