<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Settings\PlatformSettings;
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
     *
     * The shape is declared for Scramble, and not as documentation for its own
     * sake: both Nuxt apps generate their typed client from this spec, so an
     * undeclared `response()->json()` produced `data: string` and the switcher
     * failed to compile against its own API.
     *
     * @scramble-return array{data: list<array{id: int, name: string}>, acting_organization_id: int|null}
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
     *
     * @scramble-return array{acting_organization_id: int|null}
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

    /**
     * GET /api/admin/settings — the platform's own knobs.
     *
     * Superadmin only, 403 for everyone else, for the same reason the client
     * list above is: these endpoints hold no record whose existence needs
     * hiding — they are a capability, and the caller is authenticated.
     *
     * The shape is spelled out for Scramble, like `/auth/me`'s and for the same
     * reason: `app(PlatformSettings::class)->…` is a container call it cannot
     * follow, so the inferred type came out as a bare `string`. The backoffice
     * generates its client from this spec, which turned every
     * `max_questions_per_competency.standard` read into a compile error in the
     * repository that consumes it.
     *
     * @scramble-return array{data: array{max_questions_per_competency: array{standard: int, potential: int}}}
     */
    public function settings(Request $request): JsonResponse
    {
        $this->assertSuperadmin($request);

        return response()->json([
            'data' => [
                'max_questions_per_competency' => app(PlatformSettings::class)
                    ->maxQuestionsPerCompetencyMap(),
            ],
        ]);
    }

    /**
     * PATCH /api/admin/settings — change a platform knob.
     *
     * PARTIAL by design: naming only `standard` must leave `potential` where
     * it was. A settings endpoint that treats an unmentioned key as "set it to
     * nothing" turns every narrow edit into a wide one.
     *
     * The floor of 1 is not decoration. A cap of 0 does not read as "unlimited"
     * and does not read as "authoring is off" — it reads as every save failing
     * with a message about a maximum, which is the least explicable state this
     * knob could be left in. The ceiling keeps a `standard` interview an
     * interview: past a handful of predefined questions the adaptivity that is
     * the product has nothing left to do.
     *
     * @scramble-return array{data: array{max_questions_per_competency: array{standard: int, potential: int}}}
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $this->assertSuperadmin($request);

        // `Rule::array([...])` is load-bearing, not tidiness.
        //
        // Laravel excludes unvalidated array keys by default, and a key with an
        // `array` rule plus dotted sub-rules has its PARENT dropped from
        // `validated()` entirely, re-set only from the sub-keys that were
        // actually present. So a body naming no recognised sub-key —
        // `{"max_questions_per_competency": {"foo": 3}}` — passed
        // `required|array`, skipped both `sometimes` rules, and left
        // `validated()` empty: the read below was null, and under
        // `strict_types` that reached `setMaxQuestionsPerCompetency(array)` as
        // a TypeError. A 500, from a malformed request that should have been a
        // 422.
        //
        // Naming the permitted keys makes junk fail validation instead, and
        // closes the unknown-key surface as a side effect.
        $validated = $request->validate([
            'max_questions_per_competency' => ['required', 'array', Rule::array(['standard', 'potential'])],
            'max_questions_per_competency.standard' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'max_questions_per_competency.potential' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        /** @var array<string, int> $caps */
        $caps = $validated['max_questions_per_competency'] ?? [];

        $updated = app(PlatformSettings::class)->setMaxQuestionsPerCompetency($caps);

        return response()->json([
            'data' => ['max_questions_per_competency' => $updated],
        ]);
    }
}
