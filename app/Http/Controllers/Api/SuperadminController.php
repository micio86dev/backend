<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Superadmin\ClientDirectory;
use App\Support\Tenancy\ActingOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The superadmin's client list and acting-organization switch
 * (RATIFIED 2026-09-02, option b).
 *
 * 403 rather than 404 for a non-superadmin, and that is a deliberate
 * departure from the cross-tenant doctrine used elsewhere. The 404 rule
 * protects the existence of a RECORD belonging to somebody else; these
 * endpoints hold no record, they are a capability, and the caller is
 * authenticated. Hiding the route would tell an org admin nothing they cannot
 * already infer, while making a real misconfiguration look like a typo.
 */
class SuperadminController extends Controller
{
    /**
     * Refuse anyone who is not a superadmin.
     *
     * Checked here rather than left to a policy because there is no model to
     * authorize against — the subject is the caller, not a row.
     */
    private function assertSuperadmin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_superadmin === true, Response::HTTP_FORBIDDEN);

        // Returned rather than re-read at the call sites: `$request->user()` is
        // nullable everywhere, so each caller would otherwise repeat a null
        // check the abort above has already settled.
        return $user;
    }

    /**
     * Every client, for the switcher.
     *
     * Reachable ONLY by a superadmin, so the cross-tenant read is the point
     * rather than a leak — and it returns identity only. Nothing about
     * webhooks, credentials or settings crosses here: the switcher needs a
     * name to put in a menu, and every other read stays behind the acting
     * organization the caller then selects.
     */
    public function organizations(Request $request): JsonResponse
    {
        $user = $this->assertSuperadmin($request);

        // Through ClientDirectory, not an unscoped query here:
        // AdminTenancySafetyArchTest forbids `withoutGlobalScopes(` anywhere
        // under app/Http/, and that rule is right — an unscoped query in a
        // controller is the shape a cross-tenant leak takes. The one
        // deliberate cross-tenant read lives in a single auditable class.
        return response()->json([
            'data' => app(ClientDirectory::class)->all(),
            'acting_organization_id' => app(ActingOrganization::class)->for((int) $user->id),
        ]);
    }

    /**
     * Select a client to act as, or `null` to see them all again.
     *
     * The id is validated against the table rather than trusted: an unknown
     * id would otherwise scope every subsequent read to an organization that
     * does not exist, and the superadmin would see an empty product with no
     * explanation.
     */
    public function setActingOrganization(Request $request): JsonResponse
    {
        $user = $this->assertSuperadmin($request);

        $validated = $request->validate([
            'organization_id' => ['present', 'nullable', 'integer', Rule::exists('organizations', 'id')],
        ]);

        $organizationId = $validated['organization_id'] === null
            ? null
            : (int) $validated['organization_id'];

        app(ActingOrganization::class)->set((int) $user->id, $organizationId);

        return response()->json(['acting_organization_id' => $organizationId]);
    }
}
