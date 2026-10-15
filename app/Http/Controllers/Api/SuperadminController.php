<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePlatformSettingsRequest;
use App\Models\User;
use App\Support\Settings\PlatformSettings;
use App\Support\Superadmin\ClientDirectory;
use App\Support\Superadmin\ClientOverviewReader;
use App\Support\Superadmin\PlatformAuditWriter;
use App\Support\Tenancy\ActingOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly PlatformAuditWriter $audit) {}

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

    // Internal notes, not published (Scramble exports docblock prose as public text):
    // Every client, with the platform-wide statistics the console renders.
    //
    // Reachable ONLY by a superadmin. `ClientOverviewReader` strips the
    // tenant scope itself — an "Act as" selection MUST NOT narrow this
    // estate to one client (design D2 Trap 1): the superadmin's own ambient
    // bypass goes OFF the moment they act as somebody, and a scoped read
    // would then return one organization instead of every client the page
    // exists to show.
    //
    // The shape is declared for Scramble for the same reason `organizations()`
    // and `settings()` already are: `app(ClientOverviewReader::class)->all()`
    // is a container call it cannot follow.
    /**
     * Every client, with the platform-wide statistics the console renders.
     *
     * Reachable only by a superadmin. The list is never narrowed by an "Act as" selection: it always
     * covers every client.
     *
     * @scramble-return array{
     *     data: list<array{
     *         id: int, name: string, created_at: string|null,
     *         projects: int, candidates: int, completed: int, errored: int,
     *         last_activity_at: string|null,
     *     }>,
     *     acting_organization_id: int|null,
     * }
     */
    public function clients(Request $request): JsonResponse
    {
        $user = $this->assertSuperadmin($request);

        return response()->json([
            'data' => app(ClientOverviewReader::class)->all(),
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
     * @scramble-return array{data: array{max_questions_per_competency: array{standard: int, potential: int}, interview_intro_phrase: array{it?: string, en?: string}|null, interview_closing_phrase: array{it?: string, en?: string}|null}}
     */
    public function settings(Request $request): JsonResponse
    {
        $this->assertSuperadmin($request);

        return response()->json(['data' => $this->settingsPayload(app(PlatformSettings::class))]);
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
     * The `interview_intro_phrase` and `interview_closing_phrase` maps replace the stored one and `null`
     * clears it; each change writes an audit row. The whole PATCH runs in one transaction, so a failed audit row applies nothing. The
     * caps stay unaudited, as before.
     *
     * @scramble-return array{data: array{max_questions_per_competency: array{standard: int, potential: int}, interview_intro_phrase: array{it?: string, en?: string}|null, interview_closing_phrase: array{it?: string, en?: string}|null}}
     */
    public function updateSettings(UpdatePlatformSettingsRequest $request): JsonResponse
    {
        $user = $this->assertSuperadmin($request);

        $validated = $request->validated();
        $settings = app(PlatformSettings::class);

        DB::transaction(function () use ($validated, $settings, $user): void {
            if (isset($validated['max_questions_per_competency'])) {
                /** @var array<string, int> $caps */
                $caps = $validated['max_questions_per_competency'];
                $settings->setMaxQuestionsPerCompetency($caps);
            }

            if (array_key_exists('interview_intro_phrase', $validated)) {
                $this->changeIntro($settings, $user, $validated['interview_intro_phrase']);
            }

            if (array_key_exists('interview_closing_phrase', $validated)) {
                $this->changeClosing($settings, $user, $validated['interview_closing_phrase']);
            }
        });

        return response()->json(['data' => $this->settingsPayload($settings)]);
    }

    /**
     * Replace the intro map and audit the change; no row when nothing changed.
     * Runs inside the caller's transaction, so a failed audit row rolls the write back.
     *
     * @param  array<string, string>|null  $map
     */
    private function changeIntro(PlatformSettings $settings, User $actor, ?array $map): void
    {
        $before = $settings->interviewIntroPhraseMap();
        $after = $settings->setInterviewIntroPhrase($map);

        if ($before === $after) {
            return;
        }

        $this->audit->record(
            $actor->id,
            'platform_settings.interview_intro_phrase.updated',
            'PlatformSetting',
            null,
            ['interview_intro_phrase' => $before],
            ['interview_intro_phrase' => $after],
        );
    }

    /**
     * Replace the closing map and audit the change; same semantics as the intro.
     *
     * @param  array<string, string>|null  $map
     */
    private function changeClosing(PlatformSettings $settings, User $actor, ?array $map): void
    {
        $before = $settings->interviewClosingPhraseMap();
        $after = $settings->setInterviewClosingPhrase($map);

        if ($before === $after) {
            return;
        }

        $this->audit->record(
            $actor->id,
            'platform_settings.interview_closing_phrase.updated',
            'PlatformSetting',
            null,
            ['interview_closing_phrase' => $before],
            ['interview_closing_phrase' => $after],
        );
    }

    /**
     * @return array{max_questions_per_competency: array<string, int>, interview_intro_phrase: array<string, string>|null, interview_closing_phrase: array<string, string>|null}
     */
    private function settingsPayload(PlatformSettings $settings): array
    {
        return [
            'max_questions_per_competency' => $settings->maxQuestionsPerCompetencyMap(),
            'interview_intro_phrase' => $settings->interviewIntroPhraseMap() ?: null,
            'interview_closing_phrase' => $settings->interviewClosingPhraseMap() ?: null,
        ];
    }
}
