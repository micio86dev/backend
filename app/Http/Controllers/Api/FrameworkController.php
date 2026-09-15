<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BarsIndicatorResource;
use App\Http\Resources\CompetencyResource;
use App\Http\Resources\FrameworkVersionResource;
use App\Http\Resources\RoleResource;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Role;
use App\Support\Catalogue\CatalogueRevisionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * FrameworkController (C3 Framework Catalog).
 *
 * Read-only API serving the global BEAI framework catalog.
 * Behind auth:api middleware (C2 JWT auth + TenantContext).
 *
 * Routes (all under /api/framework):
 *   GET /framework/roles
 *   GET /framework/roles/{roleCode}/competencies
 *   GET /framework/roles/{roleCode}/competencies/{competencyCode}/indicators
 *
 * Locale resolution order (applied once per request):
 *   1. ?locale= query param — validated ∈ config('app.supported_locales')
 *   2. Accept-Language header — parsed and matched against supported_locales
 *   3. config('app.fallback_locale') (en)
 *
 * A missing FrameworkVersion MUST return 200 with the global catalog and pin_context: null.
 * NEVER call firstOrFail() on FrameworkVersion.
 *
 * Revision scoping (framework-catalogue-authoring PR3b, H1): every action
 * below is called BEFORE a project exists — there is no pin to resolve
 * against yet — so every read is scoped to the LATEST PUBLISHED revision via
 * `CatalogueRevisionResolver`, never a draft. Once a role/competency is
 * resolved to a specific, revision-correct row, every relation traversed
 * from it (`Role::competencies()`, `BarsIndicator::where('role_id', ...)`)
 * is automatically safe: the composite FKs on `framework_role_competency`
 * and `framework_bars_indicators` guarantee a row's `role_id`/
 * `competency_id` and its own `revision_id` always agree, so a stray
 * cross-revision match is impossible once the STARTING row is correct.
 *
 * `tryLatestPublished()`, never `latestPublished()`: this class's own
 * docblock above promises "a missing FrameworkVersion MUST return 200 with
 * the global catalog" — an unseeded platform with no published revision at
 * all is the same class of "nothing to show yet", not a 500. `NO_PUBLISHED_
 * REVISION` is an impossible id (every real revision id is a positive
 * serial), so every query below naturally resolves to empty/404 instead.
 */
class FrameworkController extends Controller
{
    private const NO_PUBLISHED_REVISION = -1;

    public function __construct(
        private readonly CatalogueRevisionResolver $revisionResolver,
    ) {}

    private function latestPublishedOrSentinel(): int
    {
        return $this->revisionResolver->tryLatestPublished() ?? self::NO_PUBLISHED_REVISION;
    }

    /**
     * GET /api/framework/roles
     *
     * Returns all global roles for ANY authenticated org.
     * No FrameworkVersion required — if absent, pin_context is null.
     */
    public function index(Request $request): JsonResponse
    {
        $this->resolveLocale($request);

        $roles = Role::with('competencies')
            ->where('revision_id', $this->latestPublishedOrSentinel())
            ->get();

        // Optionally surface org's FrameworkVersion as pin_context (if it exists)
        $pinContext = FrameworkVersion::first()?->only(['id', 'version', 'label', 'is_locked']);

        return RoleResource::collection($roles)
            ->additional(['pin_context' => $pinContext])
            ->response();
    }

    /**
     * GET /api/framework/roles/{roleCode}/competencies
     *
     * Returns competencies for a specific role + bars_available flag (N+1-free).
     */
    public function roleCompetencies(Request $request, string $roleCode): JsonResponse
    {
        $this->resolveLocale($request);

        $role = Role::where('code', strtoupper($roleCode))
            ->where('revision_id', $this->latestPublishedOrSentinel())
            ->firstOrFail();

        $competencies = $role->competencies()->get();

        // Preload covered competency IDs in ONE query (N+1-free bars_available check)
        $barsCoveredIds = BarsIndicator::where('role_id', $role->id)
            ->distinct()
            ->pluck('competency_id')
            ->toArray();

        // Pass the covered set to each resource via ->additional()
        $resources = $competencies->map(
            fn (Competency $competency): CompetencyResource => (new CompetencyResource($competency))
                ->additional(['bars_covered_ids' => $barsCoveredIds])
        );

        return CompetencyResource::collection($resources)->response();
    }

    /**
     * GET /api/framework/potential-competencies
     *
     * The competencies a `potential` assessment scores: MTG and LAT.
     *
     * They belong to NO role — that is what makes them the potential set —
     * so `roleCompetencies` above cannot serve them, and the backoffice was
     * building them locally from two hardcoded codes with no `id`. Without an
     * id `CompetencyPicker` refuses to tick a box, so a `potential` project
     * could not have its competencies selected at all: both boxes rendered,
     * neither responded, and an already-persisted set rendered unchecked.
     *
     * Driven by `type`, never by a hardcoded code list: the catalogue decides
     * which competencies are potential, and a third one must appear here the
     * day it is authored rather than the day someone edits this method.
     *
     * `bars_available` is deliberately false for every row. Coverage is a
     * question about a role×competency pair, and these belong to no role —
     * the same reason the frontend's local list answered `null` for it.
     */
    public function potentialCompetencies(Request $request): JsonResponse
    {
        $this->resolveLocale($request);

        $competencies = Competency::query()
            ->where('type', 'potential')
            ->where('revision_id', $this->latestPublishedOrSentinel())
            ->orderBy('code')
            ->get();

        $resources = $competencies->map(
            fn (Competency $competency): CompetencyResource => (new CompetencyResource($competency))
                ->additional(['bars_covered_ids' => []])
        );

        return CompetencyResource::collection($resources)->response();
    }

    /**
     * GET /api/framework/roles/{roleCode}/competencies/{competencyCode}/indicators
     *
     * Returns BARS indicators + anchors for a role×competency pair.
     */
    public function competencyBars(Request $request, string $roleCode, string $competencyCode): JsonResponse
    {
        $this->resolveLocale($request);

        $latestPublished = $this->latestPublishedOrSentinel();

        $role = Role::where('code', strtoupper($roleCode))
            ->where('revision_id', $latestPublished)
            ->firstOrFail();
        $competency = Competency::where('code', strtoupper($competencyCode))
            ->where('revision_id', $latestPublished)
            ->firstOrFail();

        $indicators = BarsIndicator::where('role_id', $role->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get();

        return BarsIndicatorResource::collection($indicators)->response();
    }

    /**
     * GET /api/framework/versions
     *
     * Returns all FrameworkVersions belonging to the authenticated org.
     * TenantScoped global scope limits results to own-org versions only.
     * Used by clients when creating a Project to choose which FV to pin.
     *
     * Added by C4.
     */
    public function versions(): AnonymousResourceCollection
    {
        $versions = FrameworkVersion::all();

        return FrameworkVersionResource::collection($versions);
    }

    /**
     * Resolve and set the app locale for this request.
     *
     * Order: ?locale= param → Accept-Language header → fallback_locale (en).
     * Calls App::setLocale() once; spatie accessors use the active locale transparently.
     *
     * Validation:
     *   - An explicit ?locale= that is NOT in config('app.supported_locales') → abort 422.
     *   - Accept-Language header with an unsupported value degrades gracefully to fallback (NOT 422).
     */
    private function resolveLocale(Request $request): void
    {
        /** @var list<string> $supportedLocales */
        $supportedLocales = config('app.supported_locales', ['en']);

        // 1. Explicit ?locale= param — validated ∈ supported_locales; unsupported → 422.
        $queryLocale = $request->query('locale');
        if ($queryLocale !== null) {
            Validator::make(
                ['locale' => $queryLocale],
                ['locale' => ['required', 'string', Rule::in($supportedLocales)]],
            )->validate();

            App::setLocale($queryLocale);

            return;
        }

        // 2. Accept-Language header — advisory only; unsupported value degrades to fallback.
        $acceptLanguage = $request->header('Accept-Language', '');
        if (! empty($acceptLanguage)) {
            // Parse first language tag (e.g. "it-IT,it;q=0.9,en;q=0.8" → "it")
            $primaryTag = strtolower(explode(',', $acceptLanguage)[0]);
            $primaryLang = explode('-', explode(';', $primaryTag)[0])[0];

            if (in_array($primaryLang, $supportedLocales, true)) {
                App::setLocale($primaryLang);

                return;
            }
        }

        // 3. Fallback locale (en)
        App::setLocale(config('app.fallback_locale', 'en'));
    }
}
