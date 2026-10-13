<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicApi\OrganizationResource;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * `GET /v1/organization`. No scope is required: every authenticated key may read
 * its own organization.
 */
final class OrganizationController extends Controller
{
    /**
     * Get the organization.
     *
     * Returns the organization the API key belongs to, together with the mode of the key.
     */
    public function show(): JsonResponse
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        // Resolved explicitly rather than trusted: the tenant middleware already answers 401 first.
        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        if ($organization === null) {
            abort(404);
        }

        /** @var ApiClient $client */
        $client = Auth::guard('api-m2m')->user();

        return (new OrganizationResource($organization, $client->mode))->response();
    }
}
