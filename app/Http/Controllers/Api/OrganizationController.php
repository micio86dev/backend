<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\Admin\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * OrganizationController (backoffice-missing-pages D2).
 *
 * GET/PATCH /api/organization — the singular, self-resolving org settings
 * resource. No id ever appears in the path or is read from the request; the
 * org resolves EXCLUSIVELY from `$request->user()->organization_id` (the
 * M2m/ParticipantController.php:110-111 explicit-resolve discipline, minus
 * even the id — there is no IDOR surface to test here at all).
 */
class OrganizationController extends Controller
{
    /**
     * GET /api/organization
     */
    public function show(): OrganizationResource
    {
        /** @var User $user */
        $user = request()->user();
        $organization = Organization::findOrFail($user->organization_id);

        $this->authorize('view', $organization);

        return new OrganizationResource($organization);
    }

    /**
     * PATCH /api/organization
     *
     * `slug` is intentionally never read from the request — only()
     * whitelists the writable fields, so a `slug` key in the body is
     * silently dropped rather than validated-then-rejected (D2).
     */
    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = Organization::findOrFail($user->organization_id);

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
        ]));

        return (new OrganizationResource($organization))->response();
    }
}
