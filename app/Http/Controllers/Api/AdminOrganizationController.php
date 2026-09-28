<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\CreateOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateManagedOrganizationRequest;
use App\Http\Resources\Admin\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Support\Superadmin\PlatformAuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Superadmin organization (client) management — complements the read-only
 * `admin/organizations` list and the acting-organization switch
 * (`SuperadminController`).
 *
 * The target is addressed by id in the path, unlike the singular tenant-facing
 * `/api/organization`, so it is resolved with `findOrFail` AFTER the superadmin
 * check: `Organization` is not tenant-scoped, and the caller is by definition
 * the one actor allowed to see all of them.
 */
class AdminOrganizationController extends Controller
{
    public function __construct(
        private readonly CreateOrganization $createOrganization,
        private readonly PlatformAuditWriter $auditWriter,
    ) {}

    /**
     * POST /api/admin/organizations
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $organization = $this->createOrganization->create(
            (string) $request->validated('name'),
            (string) $request->validated('slug'),
            $actor,
        );

        return (new OrganizationResource($organization))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * GET /api/admin/organizations/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->is_superadmin === true, Response::HTTP_FORBIDDEN);

        return (new OrganizationResource(Organization::findOrFail($id)))->response();
    }

    /**
     * PATCH /api/admin/organizations/{id}
     */
    public function update(UpdateManagedOrganizationRequest $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $organization = Organization::findOrFail($id);

        $organization->fill($request->safe()->only(['name', 'primary_color']));
        $changes = $organization->getDirty();
        $before = array_intersect_key($organization->getOriginal(), $changes);
        $organization->save();

        if ($changes !== []) {
            $this->auditWriter->record(
                actorId: $actor->id,
                action: 'organization.updated',
                subjectType: 'Organization',
                subjectId: $organization->id,
                before: $before,
                after: $changes,
            );
        }

        return (new OrganizationResource($organization))->response();
    }
}
