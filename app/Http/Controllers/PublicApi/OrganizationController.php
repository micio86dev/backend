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
 * `GET /v1/organization` — BEAI Public API (public-api step 4), SPEC.md
 * §3.3 "any scope | `id, name, mode` (of the key), `default_language?`,
 * `allowed_domains`, `created_at`" and `openapi.yaml`'s `getOrganization`
 * operation. No `scope:` middleware — every authenticated key may read its
 * own organization.
 */
final class OrganizationController extends Controller
{
    public function show(): JsonResponse
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        // Unreachable through the real /v1 stack — PublicApiTenantContext
        // already 401s a null/invalid org id before this controller ever
        // runs — but resolved explicitly rather than trusted, same
        // discipline as RateLimitPublicApi/IdempotencyKey's own defensive
        // client checks.
        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        if ($organization === null) {
            abort(404);
        }

        /** @var ApiClient $client */
        $client = Auth::guard('api-m2m')->user();

        return (new OrganizationResource($organization, $client->mode))->response();
    }
}
