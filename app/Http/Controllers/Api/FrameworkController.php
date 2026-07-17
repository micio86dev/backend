<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BarsIndicatorResource;
use App\Http\Resources\CompetencyResource;
use App\Http\Resources\RoleResource;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\App;

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
 */
class FrameworkController extends Controller
{
    /**
     * GET /api/framework/roles
     *
     * Returns all global roles for ANY authenticated org.
     * No FrameworkVersion required — if absent, pin_context is null.
     */
    public function index(Request $request): JsonResponse
    {
        $this->resolveLocale($request);

        $roles = Role::with('competencies')->get();

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

        $role = Role::where('code', strtoupper($roleCode))->firstOrFail();

        $competencies = $role->competencies()->get();

        // Preload covered competency IDs in ONE query (N+1-free bars_available check)
        $barsCoveredIds = BarsIndicator::where('role_id', $role->id)
            ->distinct()
            ->pluck('competency_id')
            ->toArray();

        // Pass the covered set to each resource via ->additional()
        $collection = CompetencyResource::collection($competencies);
        $collection->each(function (CompetencyResource $resource) use ($barsCoveredIds): void {
            $resource->additional(['bars_covered_ids' => $barsCoveredIds]);
        });

        return $collection->response();
    }

    /**
     * GET /api/framework/roles/{roleCode}/competencies/{competencyCode}/indicators
     *
     * Returns BARS indicators + anchors for a role×competency pair.
     */
    public function competencyBars(Request $request, string $roleCode, string $competencyCode): JsonResponse
    {
        $this->resolveLocale($request);

        $role = Role::where('code', strtoupper($roleCode))->firstOrFail();
        $competency = Competency::where('code', strtoupper($competencyCode))->firstOrFail();

        $indicators = BarsIndicator::where('role_id', $role->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get();

        return BarsIndicatorResource::collection($indicators)->response();
    }

    /**
     * Resolve and set the app locale for this request.
     *
     * Order: ?locale= param → Accept-Language header → fallback_locale (en).
     * Calls App::setLocale() once; spatie accessors use the active locale transparently.
     */
    private function resolveLocale(Request $request): void
    {
        /** @var list<string> $supportedLocales */
        $supportedLocales = config('app.supported_locales', ['en']);

        // 1. Explicit ?locale= param
        $queryLocale = $request->query('locale');
        if ($queryLocale !== null && in_array($queryLocale, $supportedLocales, true)) {
            App::setLocale($queryLocale);

            return;
        }

        // 2. Accept-Language header
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
