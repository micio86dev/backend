<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Enums\AssessmentType;
use App\Enums\ProjectStatus;
use App\Enums\RoleCode;
use App\Exceptions\PublicApi\QueryValidationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicApi\ProjectResource;
use App\Models\Project;
use App\Support\PublicApi\CursorPage;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicId;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * `GET /v1/projects` and `GET /v1/projects/{id}` — BEAI Public API
 * (public-api step 4), SPEC.md §3.3 and §3.4, `openapi.yaml`'s
 * `listProjects`/`getProject` operations. Both require `scope:projects:read`
 * (applied per-route in `routes/api.php`).
 *
 * `Project` is a `TenantModel` — every query here is ALREADY scoped to the
 * authenticated organization by `App\Models\Concerns\TenantScoped`'s global
 * scope (stamped by `App\Http\Middleware\PublicApi\PublicApiTenantContext`
 * earlier in the `/v1` middleware stack); this class adds no tenancy
 * filtering of its own. Soft-deleted projects are excluded for the same
 * reason every other read in this codebase excludes them — `SoftDeletes`'s
 * own default scope — never re-implemented here.
 */
final class ProjectController extends Controller
{
    private const EAGER_LOAD = ['frameworkVersion', 'avatarTemplate', 'competencies'];

    /**
     * SPEC.md §3.2 "Filtering on list endpoints: `status`, ...". `role_code`
     * and `assessment_type` are Public-API-specific additions this
     * operation's own contract entry lists (`openapi.yaml` `listProjects`
     * parameters). An unrecognised value for any of the three answers `400
     * validation_failed` via `QueryValidationException` (G-28) — a
     * malformed QUERY PARAMETER, never `422`.
     *
     * `data[]` documented as a list of `PublicProject` objects and
     * `next_cursor` as nullable (step 5 review follow-up, Part B item 2) —
     * same fix, same reasoning, as `InterviewController::index()`'s own
     * docblock. `#[IgnoreResponse]`/`#[Response(400, ...)]` (Part B item 6)
     * replace the incorrect auto-inferred `422` this method's own
     * `QueryValidationException` throw produced — the shape now shared
     * from `Problem::PROBLEM_SHAPE` (step 6 review follow-up, Part A item
     * 6) rather than a copy of the constant this class used to declare.
     *
     * @response array{data: list<\App\Http\Resources\PublicApi\ProjectResource>, next_cursor: string|null, has_more: bool}
     */
    #[IgnoreResponse(422)]
    #[Response(400, description: 'Malformed query parameter.', type: Problem::PROBLEM_SHAPE)]
    #[QueryParameter('cursor', description: 'Opaque pagination cursor from a previous page\'s next_cursor. Omit for the first page. A present but malformed value answers 400 invalid_cursor.', type: 'string')]
    #[QueryParameter('limit', description: 'Page size, 1-100 (default 25). Out of range answers 400 validation_failed.', type: 'integer')]
    #[QueryParameter('status', description: 'Filter by project status.', type: 'string')]
    #[QueryParameter('role_code', description: 'Filter by role code (ICO, FLL, MLL, BUL, SRX).', type: 'string')]
    #[QueryParameter('assessment_type', description: 'Filter by assessment type (standard or potential).', type: 'string')]
    public function index(Request $request): JsonResponse
    {
        $this->validateFilters($request);

        $query = Project::query()->with(self::EAGER_LOAD);

        foreach (['status', 'role_code', 'assessment_type'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        $page = CursorPage::paginate(
            $query,
            $request,
            fn (Project $project): array => ProjectResource::make($project)->resolve($request),
        );

        return response()->json($page);
    }

    /**
     * `$project` is the RAW path segment (`prj_...`), resolved manually
     * rather than through implicit Eloquent route-model binding: the
     * existing admin `Route::apiResource('projects', ProjectController::class)`
     * already binds the SAME `{project}` route parameter name to an
     * integer id, and a second, public-id-based binding registered on the
     * same parameter name would either collide with it or require touching
     * `App\Models\Project::resolveRouteBinding()` globally — which the
     * admin surface must never see (it keeps using integer ids). Resolving
     * by hand here keeps the two surfaces fully independent, and
     * `PublicId::decode()` returning `null` on ANY malformed/mismatched-
     * prefix input, funnelled into the exact same "no row" 404 branch as a
     * syntactically valid but unknown id, is what guarantees a mismatched
     * prefix answers `404 not_found`, never `400` (SPEC.md §3.2).
     */
    public function show(Request $request, string $project): JsonResponse
    {
        $bareId = PublicId::decode($project, Project::publicIdPrefix());

        $model = $bareId === null
            ? null
            : Project::query()->with(self::EAGER_LOAD)->wherePublicId($bareId)->first();

        if ($model === null) {
            abort(404);
        }

        return ProjectResource::make($model)->response();
    }

    private function validateFilters(Request $request): void
    {
        $validator = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', Rule::in(ProjectStatus::values())],
            'role_code' => ['sometimes', 'string', Rule::in(RoleCode::values())],
            'assessment_type' => ['sometimes', 'string', Rule::in(AssessmentType::values())],
        ]);

        if ($validator->fails()) {
            // Mirrors CursorPage::resolveLimit()'s own G-28 discipline: a
            // QUERY-parameter failure must render 400, never the 422 a bare
            // Validator::validate() call would throw for a request body.
            throw new QueryValidationException($validator);
        }
    }
}
