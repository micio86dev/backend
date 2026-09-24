<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Actions\PublicApi\EnrolCandidate;
use App\Exceptions\PublicApi\EnrolmentRefusalReason;
use App\Exceptions\PublicApi\EnrolmentRefused;
use App\Exceptions\PublicApi\QueryValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicApi\CreateInterviewRequest;
use App\Http\Resources\PublicApi\InterviewResource;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\PublicApi\Serializers\InterviewSerializer;
use App\Support\PublicApi\CursorPage;
use App\Support\PublicApi\Expand;
use App\Support\PublicApi\HostedInterviewUrlComposer;
use App\Support\PublicApi\InterviewStatus;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/interviews`, `GET /v1/interviews` and `GET /v1/interviews/{id}`
 * — BEAI Public API (public-api step 5), SPEC.md §3.3, `openapi.yaml`'s
 * `createInterview`/`listInterviews`/`getInterview` operations.
 *
 * `Interview` is the `participants` enrolment (ruling 8, G-02) — a plain
 * `Model`, NOT a `TenantModel` (see `App\Models\Participant`'s own
 * docblock), so every query here filters `organization_id` EXPLICITLY from
 * `App\Support\Tenancy\TenantResolver`, the same discipline
 * `App\Support\Sso\EntryLinkMinter` and `App\Support\Project\
 * ProjectInterviewability` already apply for the identical reason.
 */
final class InterviewController extends Controller
{
    private const EXPANDABLE = ['project'];

    public function __construct(
        private readonly EnrolCandidate $enrolCandidate,
        private readonly HostedInterviewUrlComposer $hostedInterviewUrlComposer,
    ) {}

    public function store(CreateInterviewRequest $request): JsonResponse
    {
        $organization = $this->resolveOrganization();

        $project = $this->resolveProject($request->string('project_id')->toString());

        if ($project === null) {
            abort(404);
        }

        /** @var ApiClient $client */
        $client = Auth::guard('api-m2m')->user();

        $language = $request->input('candidate.language');

        try {
            $result = $this->enrolCandidate->handle(
                $project,
                $organization,
                [
                    'candidate_ref' => $request->string('candidate.candidate_ref')->toString(),
                    'email' => $request->string('candidate.email')->toString(),
                    'display_name' => $request->string('candidate.display_name')->toString(),
                    'language' => is_string($language) ? $language : null,
                ],
                self::stringMap($request->input('metadata')),
                self::nullableString($request->input('exit_redirect_url')),
                $client->mode,
            );
        } catch (EnrolmentRefused $e) {
            return $this->renderRefusal($request, $e->reason);
        }

        $hostedUrl = $this->hostedInterviewUrlComposer->compose($result->sessionToken->token, $result->participant->language);

        return response()->json([
            'interview' => InterviewResource::make($result->participant)->resolve($request),
            'session_token' => $result->sessionToken->token,
            'expires_at' => $result->sessionToken->expiresAt->toISOString(),
            'hosted_url' => $hostedUrl,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $expand = Expand::parse($request, self::EXPANDABLE);

        // Validated ONLY to fail fast with a QueryValidationException (400)
        // on a malformed value — the actual filter VALUES below are read
        // straight from $request->query() with explicit is_string()/
        // is_array() narrowing, never from Validator::validated() (whose
        // return type is a bare, unindexed `array` PHPStan cannot narrow
        // per-key) — mirrors `ProjectController::index()`'s own convention.
        $this->validateFilterFormats($request);

        $query = Participant::query()->where('organization_id', $organization->id);

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', InterviewStatus::from($status)->toStored());
        }

        $projectIdFilter = $request->query('project_id');
        if (is_string($projectIdFilter) && $projectIdFilter !== '') {
            $bareId = PublicId::decode($projectIdFilter, Project::publicIdPrefix());
            // A malformed/mismatched project_id filter matches NOTHING
            // rather than 400 — SPEC.md §3.2 treats an id filter like a
            // path parameter (mismatched prefix → no match), never a
            // format error on a list operation.
            $internalId = $bareId === null ? null : Project::query()->wherePublicId($bareId)->value('id');
            $query->where('project_id', is_int($internalId) ? $internalId : -1);
        }

        $email = $request->query('email');
        if (is_string($email) && $email !== '') {
            $query->whereRaw('lower(email) = ?', [mb_strtolower($email)]);
        }

        $candidateRef = $request->query('candidate_ref');
        if (is_string($candidateRef) && $candidateRef !== '') {
            $query->where('candidate_ref', $candidateRef);
        }

        $createdAfter = $request->query('created_after');
        if (is_string($createdAfter) && $createdAfter !== '') {
            $query->where('created_at', '>=', $createdAfter);
        }

        $createdBefore = $request->query('created_before');
        if (is_string($createdBefore) && $createdBefore !== '') {
            $query->where('created_at', '<', $createdBefore);
        }

        $metadata = self::stringMap($request->query('metadata')) ?? [];
        foreach ($metadata as $key => $value) {
            $query->whereRaw('metadata ->> ? = ?', [$key, $value]);
        }

        $expandProject = in_array('project', $expand, true);

        // gga finding 4: ALWAYS eager-load project — never let
        // InterviewSerializer's `project_id`/`PublicId::encode($project)`
        // lazy-load one query per row. `?expand=project` needs the FULL
        // Project (plus the SAME nested relations ProjectController::index()
        // eager-loads for ProjectSerializer); the unexpanded case only ever
        // reads `id`/`public_id`, so it stays column-restricted.
        //
        // `withTrashed()`-equivalent on EVERY branch (gga round 3 finding 1):
        // a participant whose project was later soft-deleted must stay
        // readable — see `InterviewSerializer::project()`'s own docblock and
        // `self::projectIncludingTrashed()`'s own docblock for why this
        // reaches it via `getQuery()->withoutGlobalScope()` rather than
        // calling `withTrashed()` directly on the closure's relation
        // argument.
        $query->with($expandProject
            ? ['project' => fn (Relation $relation): Builder => self::projectIncludingTrashed($relation)->with(['frameworkVersion', 'avatarTemplate', 'competencies'])]
            : ['project' => fn (Relation $relation): Builder => self::projectIncludingTrashed($relation)->select(['id', 'public_id'])]);

        // Fetch the page's raw Participant models first (identity map),
        // batch-compute progress for the WHOLE page in one call — two
        // queries total, never one per row (gga finding 4) — THEN serialize
        // each row with its own precomputed slice.
        $rawPage = CursorPage::paginate(
            $query,
            $request,
            fn (Participant $participant): Participant => $participant,
        );

        /** @var list<Participant> $participants */
        $participants = $rawPage['data'];
        $progressByParticipant = InterviewSerializer::progressForMany($participants);

        $rawPage['data'] = array_map(
            fn (Participant $participant): array => InterviewResource::make(
                $participant,
                $expandProject,
                $progressByParticipant[$participant->id] ?? [],
            )->resolve($request),
            $participants,
        );

        return response()->json($rawPage);
    }

    public function show(Request $request, string $interview): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $expand = Expand::parse($request, self::EXPANDABLE);
        $expandProject = in_array('project', $expand, true);

        $participant = $this->resolveParticipant($interview, $organization, $expandProject);

        if ($participant === null) {
            abort(404);
        }

        return InterviewResource::make($participant, $expandProject)->response();
    }

    private function resolveOrganization(): Organization
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        // Unreachable through the real /v1 stack (PublicApiTenantContext
        // already 401s first) — resolved explicitly rather than trusted,
        // same defensive discipline as OrganizationController::show().
        if ($organization === null) {
            abort(404);
        }

        return $organization;
    }

    private function resolveProject(string $rawId): ?Project
    {
        $bareId = PublicId::decode($rawId, Project::publicIdPrefix());

        if ($bareId === null) {
            return null;
        }

        // Project IS a TenantModel — already scoped to the ambient
        // TenantContext this controller runs under; no explicit
        // organization filter needed (and none was previously used despite
        // the parameter — gga finding 7).
        //
        // Deliberately NOT `withTrashed()` (gga round 3 finding 1 — decided,
        // not an oversight): a soft-deleted project answers `404` here,
        // same as an unknown one. `store()`/`EnrolCandidate` starts a NEW
        // enrolment; unlike a READ of an interview that already exists (see
        // `InterviewSerializer::project()`), there is no calling-system
        // data to preserve for a project that no longer exists — and
        // `EnrolCandidate::handle()`'s own active-project gate
        // (`EntryLinkMinter::projectIsAccessible()`) would refuse a trashed
        // project anyway; failing here, at the same 404 an unknown
        // `project_id` already gets, is simpler than reaching that gate
        // only to answer a DIFFERENT status for the identical "no usable
        // project" fact.
        return Project::query()->wherePublicId($bareId)->first();
    }

    private function resolveParticipant(string $rawId, Organization $organization, bool $expandProject = false): ?Participant
    {
        $bareId = PublicId::decode($rawId, Participant::publicIdPrefix());

        if ($bareId === null) {
            return null;
        }

        // Same "always eager-load project, withTrashed()" discipline as
        // index() (gga findings 1 and 4) — a single row here, so the cost
        // is trivial either way, but `InterviewSerializer::project()`'s
        // eager-loaded branch stays the one path every `/v1` read actually
        // exercises.
        return Participant::where('organization_id', $organization->id)
            ->wherePublicId($bareId)
            ->with($expandProject
                ? ['project' => fn (Relation $relation): Builder => self::projectIncludingTrashed($relation)->with(['frameworkVersion', 'avatarTemplate', 'competencies'])]
                : ['project' => fn (Relation $relation): Builder => self::projectIncludingTrashed($relation)->select(['id', 'public_id'])])
            ->first();
    }

    /**
     * `$relation` is typed bare `Relation` (not `BelongsTo`) deliberately:
     * Larastan's `RelationForwardsCallsExtension` (which resolves a
     * model-specific macro like `withTrashed()` on a relation instance) only
     * works when the relation's generic `TRelatedModel` is CONCRETELY known
     * — which an inline `with([...])` eager-load constraint closure's own
     * parameter never is (PHPStan infers it as the wildcard `Relation<*, *,
     * *>` the base `with()` signature declares, regardless of which
     * relation name the closure is keyed under). `getQuery()` and
     * `withoutGlobalScope()` are both ORDINARILY declared methods on
     * `Relation`/`Illuminate\Database\Eloquent\Builder` — never macros — so
     * reaching the exact same effect `withTrashed()` has (dropping
     * `SoftDeletingScope`) through them needs no generic resolution at all
     * (gga round 3 finding 1).
     *
     * @param  Relation<*, *, *>  $relation
     * @return Builder<*>
     */
    private static function projectIncludingTrashed(Relation $relation): Builder
    {
        return $relation->getQuery()->withoutGlobalScope(SoftDeletingScope::class);
    }

    private function validateFilterFormats(Request $request): void
    {
        $validator = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', Rule::in(InterviewStatus::values())],
            'project_id' => ['sometimes', 'string'],
            'email' => ['sometimes', 'string', 'email'],
            'candidate_ref' => ['sometimes', 'string', 'max:255'],
            'created_after' => ['sometimes', 'date'],
            'created_before' => ['sometimes', 'date'],
            'metadata' => ['sometimes', 'array', 'max:3'],
            'metadata.*' => ['string'],
        ]);

        if ($validator->fails()) {
            throw new QueryValidationException($validator);
        }
    }

    /**
     * Narrows a `mixed` request-input value (query or body) to
     * `array<string, string>|null` — every key/value pair that is not
     * itself a string is dropped rather than trusted, a defensive belt
     * around what `App\Rules\PublicApi\Metadata` (body) or the
     * `metadata.*` query rule above already validated; a caller-supplied
     * value with the wrong SHAPE (not an array at all) becomes `null`.
     *
     * @return array<string, string>|null
     */
    private static function stringMap(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result === [] ? null : $result;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function renderRefusal(Request $request, EnrolmentRefusalReason $reason): JsonResponse
    {
        return match ($reason) {
            EnrolmentRefusalReason::ProjectNotActive => Problem::make(
                $request, 422, 'project_not_active', 'Project not active',
            ),
            EnrolmentRefusalReason::RedirectUrlNotAllowed => Problem::make(
                $request, 422, 'redirect_url_not_allowed', 'Redirect URL not allowed',
                errors: [['field' => 'exit_redirect_url', 'code' => 'redirect_url_not_allowed']],
            ),
            EnrolmentRefusalReason::DuplicateEmail => Problem::make(
                $request, 409, 'duplicate_enrolment', 'Duplicate enrolment', 'candidate.email is already enrolled in this project.',
            ),
            EnrolmentRefusalReason::DuplicateCandidateRef => Problem::make(
                $request, 409, 'duplicate_enrolment', 'Duplicate enrolment', 'candidate.candidate_ref is already enrolled in this project.',
            ),
        };
    }
}
