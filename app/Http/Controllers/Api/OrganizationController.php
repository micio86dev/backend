<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\Admin\OrganizationResource;
use App\Models\Organization;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantResolver;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * OrganizationController (backoffice-missing-pages D2).
 *
 * GET/PATCH /api/organization — the singular, self-resolving org settings
 * resource. No id ever appears in the path or is read from the request; the
 * org resolves EXCLUSIVELY from `TenantResolver::getOrgId()` (the
 * M2m/ParticipantController.php:110-111 explicit-resolve discipline, minus
 * even the id — there is no IDOR surface to test here at all).
 *
 * NOT `$request->user()->organization_id`: that column is null for a
 * superadmin, which 404'd this endpoint on every backoffice visit while
 * acting as an organization (or with none selected) — the resolver is what
 * `TenantContext` narrows to the ACTING org, and every other TenantModel read
 * already follows it for free through the global scope.
 */
class OrganizationController extends Controller
{
    /**
     * GET /api/organization
     *
     * `data: null` — never a 404 — when `getOrgId()` itself is null: a
     * superadmin with no acting organization selected (TenantContext's
     * explicit bypass branch). The backoffice shell layout calls this
     * endpoint unconditionally on every authenticated page to paint the
     * tenant's brand colour, so this is not a rare corner: it is what every
     * superadmin session hits before ever choosing "Act as", and a raw
     * `findOrFail(null)` turned that ordinary state into an unhandled
     * `ModelNotFoundException` logged as a 404 on every single page load.
     * Mirrors `RevisionController::current()`'s nullable-resource shape.
     */
    public function show(): JsonResponse
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        if ($orgId === null) {
            return response()->json(['data' => null]);
        }

        $organization = Organization::findOrFail($orgId);

        $this->authorize('view', $organization);

        return response()->json(['data' => new OrganizationResource($organization)]);
    }

    /**
     * Update the organization.
     *
     * Accepts `name`, the default webhook settings, `primary_color` and
     * `currency_code` (active catalogue codes only). Any other field in the body
     * is ignored.
     */
    #[Response(409, description: 'A superadmin must first select the organization to act for (`organization_context_required`).', type: 'array{message: string}')]
    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        $organization = Organization::findOrFail(app(TenantResolver::class)->getOrgId());

        // `slug` is intentionally never read from the request — only() whitelists the
        // writable fields, so a `slug` key in the body is silently dropped rather than
        // validated-then-rejected (D2).
        $previousCurrency = $organization->currency_code;

        $organization->update($request->safe()->only([
            'name',
            'default_webhook_url',
            'default_webhook_secret',
            'default_webhook_events',
            // `logo_path` is deliberately ABSENT: it is written by the upload
            // endpoint, which is the only place that knows a file was actually
            // stored. Accepting it here would let a client point the logo at an
            // arbitrary path on the disk.
            'primary_color',
            'currency_code',
        ]));

        // Only the currency is audited on this route (the other settings are not, a
        // pre-existing gap outside this change). Tenant-scoped writer: the row is
        // stamped with the acting organization and the authenticated user.
        if ($organization->currency_code !== $previousCurrency) {
            app(AuditRecorder::class)->record(
                'organization.currency_changed',
                'Organization',
                $organization->id,
                ['currency_code' => $previousCurrency],
                ['currency_code' => $organization->currency_code],
            );
        }

        return (new OrganizationResource($organization))->response();
    }
}
