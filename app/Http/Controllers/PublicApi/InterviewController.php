<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Actions\PublicApi\EnrolCandidate;
use App\Enums\ApiKeyMode;
use App\Exceptions\PublicApi\EnrolmentRefusalReason;
use App\Exceptions\PublicApi\EnrolmentRefused;
use App\Exceptions\PublicApi\QueryValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicApi\CreateInterviewRequest;
use App\Http\Resources\PublicApi\InterviewResource;
use App\Models\ApiClient;
use App\Models\InterviewEvent;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\PublicApi\Serializers\AnswersSerializer;
use App\PublicApi\Serializers\InterviewSerializer;
use App\PublicApi\Serializers\ScoringSerializer;
use App\PublicApi\Serializers\TranscriptSerializer;
use App\Rules\PublicApi\Iso8601DateTime;
use App\Support\Participant\ExternalReference;
use App\Support\PublicApi\ApiMode;
use App\Support\PublicApi\CursorPage;
use App\Support\PublicApi\Expand;
use App\Support\PublicApi\HostedInterviewUrlComposer;
use App\Support\PublicApi\IncludeTrashed;
use App\Support\PublicApi\InterviewStatus;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicApiJson;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\QueryFilters;
use App\Support\Tenancy\TenantResolver;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/interviews`, `GET /v1/interviews` and `GET /v1/interviews/{id}` and its sub-resources.
 *
 * An interview is the `participants` enrolment, a plain model rather than a tenant
 * model, so every query filters `organization_id` explicitly from `TenantResolver`.
 */
final class InterviewController extends Controller
{
    private const EXPANDABLE = ['project'];

    /**
     * Transcript and answers are readable from `under_evaluation` onward; an `error`
     * interview stays `409 transcript_not_ready`, so `Error` is deliberately absent.
     *
     * @var list<InterviewStatus>
     */
    private const TRANSCRIPT_READY_STATUSES = [InterviewStatus::UnderEvaluation, InterviewStatus::Completed];

    /**
     * Scoring is readable only once `completed`.
     *
     * @var list<InterviewStatus>
     */
    private const SCORING_READY_STATUSES = [InterviewStatus::Completed];

    public function __construct(
        private readonly EnrolCandidate $enrolCandidate,
        private readonly HostedInterviewUrlComposer $hostedInterviewUrlComposer,
    ) {}

    /**
     * Create an interview.
     *
     * Enrols a candidate on an active project. The response carries the new interview, a
     * short-lived session token and the URL of the hosted interview.
     *
     * @internal
     * Internal notes, not published (Scramble exports docblock prose as public text):
     * `interview` is documented as the `PublicInterview` object it always is:
     * `response()->json([...])` only exposes `InterviewResource::resolve()`'s loose
     * `array<string, mixed>` type, which would export an untyped array.
     * `#[Response(201, ...)]` is used rather than a bare `@response` tag: the PHPDoc
     * form replaces Scramble's entire inferred response and collapses the real `201`
     * to a default `200`, while the attribute names the status and keeps the other
     * inferred statuses (`404`, `409`, `422`).
     * `metadata`'s accepted shape is corrected at its source,
     * `App\Rules\PublicApi\Metadata::docs()`, so `CreateInterviewRequest`'s own schema
     * carries it directly.
     */
    #[Response(409, description: 'The candidate is already enrolled in this project (code=duplicate_enrolment).', type: Problem::PROBLEM_SHAPE)]
    #[Response(201, type: 'array{interview: \App\Http\Resources\PublicApi\InterviewResource, session_token: string, expires_at: string, hosted_url: string}')]
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

        // From the validated body, so `fromValidated()` only trims `source`.
        $validatedCandidate = $request->validated('candidate');
        $externalReference = ExternalReference::fromValidated(is_array($validatedCandidate) ? $validatedCandidate : []);

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
                $externalReference,
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

    /**
     * List interviews.
     *
     * Returns the organization's interviews for the mode of the API key, newest first, in
     * cursor-paginated pages. Every filter is optional: a filter sent with an empty value is
     * treated as not provided, and the unfiltered list is returned.
     *
     * @response array{data: list<\App\Http\Resources\PublicApi\InterviewResource>, next_cursor: string|null, has_more: bool}
     *
     * @internal
     * Internal notes, not published (Scramble exports docblock prose as public text):
     * `data[]` is a list of `PublicInterview` objects and `next_cursor` a nullable
     * string: `CursorPage::paginate()`'s `next_cursor: string|null` type does not survive
     * `response()->json($rawPage)`. `#[IgnoreResponse(422)]` and `#[Response(400, ...)]`
     * replace the incorrect inferred `422 {message, errors}` produced by this method's
     * `QueryValidationException`; see `Problem::PROBLEM_SHAPE`.
     * `created_after`/`created_before` carry explicit `#[QueryParameter]` attributes:
     * without them Scramble takes the nearest preceding code comment as the
     * description, which would publish an implementation note. The attributes state
     * the accepted format and the `400` returned on a malformed value, the contract
     * `App\Rules\PublicApi\Iso8601DateTime` enforces.
     */
    #[IgnoreResponse(422)]
    #[Response(400, description: 'Malformed query parameter.', type: Problem::PROBLEM_SHAPE)]
    #[QueryParameter('created_after', description: 'Inclusive lower bound on created_at. Strict ISO 8601 date-time, UTC (Z) or a numeric offset, e.g. 2026-01-01T00:00:00Z. An invalid or non-ISO-8601 value answers 400 validation_failed.', type: 'string')]
    #[QueryParameter('created_before', description: 'Exclusive upper bound on created_at. Strict ISO 8601 date-time, UTC (Z) or a numeric offset, e.g. 2026-01-01T00:00:00Z. An invalid or non-ISO-8601 value answers 400 validation_failed.', type: 'string')]
    #[QueryParameter('cursor', description: 'Opaque pagination cursor from a previous page\'s next_cursor. Omit for the first page. A present but malformed value answers 400 invalid_cursor.', type: 'string')]
    #[QueryParameter('limit', description: 'Page size, 1-100 (default 25). Out of range answers 400 validation_failed.', type: 'integer')]
    #[QueryParameter('external_id', description: 'Exact match on the external_id supplied as candidate.external_id when the interview was created. An integer from 1 to 9007199254740991; any other value answers 400 validation_failed. An empty value is ignored.', type: 'integer')]
    #[QueryParameter('source', description: 'Exact, case-sensitive match on the source supplied as candidate.source when the interview was created. At most 180 characters; a longer value answers 400 validation_failed. An empty value is ignored.', type: 'string')]
    #[QueryParameter('expand', description: 'Comma-separated related resources to inline. Supported: project.', type: 'string')]
    public function index(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $expand = Expand::parse($request, self::EXPANDABLE);

        // Validated only to fail fast with a 400; the filter values are read straight from the query below.
        $this->validateFilterFormats($request);

        // Mode-scoped: a test key never lists a live interview, nor the reverse.
        $mode = app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live;

        $query = Participant::query()
            ->where('organization_id', $organization->id)
            ->where('mode', $mode);

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', InterviewStatus::from($status)->toStored());
        }

        $projectIdFilter = $request->query('project_id');
        if (is_string($projectIdFilter) && $projectIdFilter !== '') {
            $bareId = PublicId::decode($projectIdFilter, Project::publicIdPrefix());
            // A malformed project_id matches nothing (not a 400); trashed projects stay findable.
            $internalId = $bareId === null ? null : Project::query()->withTrashed()->wherePublicId($bareId)->value('id');
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
            $parsedCreatedAfter = Iso8601DateTime::parse($createdAfter);

            // Converted to UTC: a numeric-offset value would otherwise bind its wall-clock digits.
            if ($parsedCreatedAfter !== null) {
                $query->where('created_at', '>=', $parsedCreatedAfter->utc());
            }
        }

        $createdBefore = $request->query('created_before');
        if (is_string($createdBefore) && $createdBefore !== '') {
            $parsedCreatedBefore = Iso8601DateTime::parse($createdBefore);

            if ($parsedCreatedBefore !== null) {
                $query->where('created_at', '<', $parsedCreatedBefore->utc());
            }
        }

        // Exact matches on the calling system's own reference; the validated integer cannot truncate.
        $externalId = $request->query('external_id');
        if (is_string($externalId) && $externalId !== '') {
            $query->where('external_id', (int) $externalId);
        }

        $source = $request->query('source');
        if (is_string($source) && $source !== '') {
            $query->where('source', $source);
        }

        $metadata = self::stringMap($request->query('metadata')) ?? [];
        foreach ($metadata as $key => $value) {
            $query->whereRaw('metadata ->> ? = ?', [$key, $value]);
        }

        $expandProject = in_array('project', $expand, true);

        // Always eager-load the project to avoid one lazy-load per row.
        $query->with(self::projectEagerLoad($expandProject));

        // Progress and recording readiness are batched for the whole page, never per row.
        $rawPage = CursorPage::paginate(
            $query,
            $request,
            fn (Participant $participant): Participant => $participant,
        );

        /** @var list<Participant> $participants */
        $participants = $rawPage['data'];
        $progressByParticipant = InterviewSerializer::progressForMany($participants);
        $recordingReadyByParticipant = InterviewSerializer::recordingReadyForMany($participants);

        $rawPage['data'] = array_map(
            fn (Participant $participant): array => InterviewResource::make(
                $participant,
                $expandProject,
                $progressByParticipant[$participant->id] ?? [],
                $recordingReadyByParticipant[$participant->id] ?? false,
            )->resolve($request),
            $participants,
        );

        return response()->json($rawPage);
    }

    /**
     * Get an interview.
     *
     * Returns one interview with its status and progress. Use `expand=project` to inline the project. A malformed or unknown interview id answers `404 not_found`.
     */
    #[QueryParameter('expand', description: 'Comma-separated related resources to inline. Supported: project.', type: 'string')]
    public function show(Request $request, string $interview): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $expand = Expand::parse($request, self::EXPANDABLE);
        $expandProject = in_array('project', $expand, true);

        $participant = $this->resolveParticipant($interview, $organization, $expandProject);

        if ($participant === null) {
            abort(404);
        }

        // The same batched progress query `index()` runs, applied to this one participant.
        $progress = InterviewSerializer::progressForMany([$participant])[$participant->id] ?? [];
        $recordingReady = InterviewSerializer::recordingReadyForMany([$participant])[$participant->id] ?? false;

        return InterviewResource::make($participant, $expandProject, $progress, $recordingReady)->response();
    }

    /**
     * Get the transcript.
     *
     * Readable once the interview is under evaluation or completed. Before that, and for an
     * interview that ended in error, the response is `409 transcript_not_ready`.
     *
     * @internal
     * Internal notes, not published (Scramble exports docblock prose as public text):
     * `GET /v1/interviews/{id}/transcript`. Gate: status
     * `under_evaluation` or `completed`, else `409 transcript_not_ready` (`error`
     * included).
     * The `200` is typed through `#[Response(200, ...)]`: the serializer output does
     * not survive inference and would export a bare `object`. The type string mirrors
     * `TranscriptSerializer::toArray()`'s `@return` shape. An attribute is used because
     * PHPDoc prose is exported verbatim as the public operation description, while an
     * attribute's `description:` is the caller-facing text for that response.
     */
    #[Response(200, description: 'The full transcript, turn by turn, in chronological order. Each turn carries its own derived question_index.', type: 'array{interview_id: string, language: string, turns: list<array{index: int, speaker: string, text: string, competency_code: string, question_index: int, ts: string}>}')]
    #[Response(409, description: 'Transcript not ready.', type: Problem::PROBLEM_SHAPE)]
    public function transcript(Request $request, string $interview): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $participant = $this->resolveParticipant($interview, $organization);

        if ($participant === null) {
            abort(404);
        }

        $notReady = $this->guardReady($request, $participant, self::TRANSCRIPT_READY_STATUSES, 'transcript_not_ready', 'Transcript not ready');

        if ($notReady !== null) {
            return $notReady;
        }

        return PublicApiJson::response(TranscriptSerializer::toArray($participant));
    }

    /**
     * Get the answers.
     *
     * Readable under the same conditions as the transcript: once the interview is under
     * evaluation or completed, otherwise `409 transcript_not_ready`.
     *
     * @internal
     * Internal notes, not published (Scramble exports docblock prose as public text):
     * `GET /v1/interviews/{id}/answers`, same read gate as the
     * transcript.
     * The `200` is typed through `#[Response(200, ...)]` for the same reason as
     * `transcript()`; it wraps `AnswersSerializer::toArray()`'s list shape in the
     * `{interview_id, answers}` envelope.
     */
    #[Response(200, description: 'The transcript grouped into one entry per question: question and answer text, and timing derived from turn timestamps.', type: 'array{interview_id: string, answers: list<array{competency_code: string, question_index: int, question_text: string, answer_text: string, started_at_seconds: float|null, answer_duration_seconds: float|null}>}')]
    #[Response(409, description: 'Answers not ready.', type: Problem::PROBLEM_SHAPE)]
    public function answers(Request $request, string $interview): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $participant = $this->resolveParticipant($interview, $organization);

        if ($participant === null) {
            abort(404);
        }

        $notReady = $this->guardReady($request, $participant, self::TRANSCRIPT_READY_STATUSES, 'transcript_not_ready', 'Answers not ready');

        if ($notReady !== null) {
            return $notReady;
        }

        return PublicApiJson::response([
            'interview_id' => PublicId::encode($participant),
            'answers' => AnswersSerializer::toArray($participant),
        ]);
    }

    /**
     * Get the scoring.
     *
     * Readable only once the interview is completed, otherwise `409 scoring_not_ready`.
     *
     * @internal
     * Internal notes, not published (Scramble exports docblock prose as public text):
     * `GET /v1/interviews/{id}/scoring`. Gate: status `completed` only,
     * else `409 scoring_not_ready`.
     * The `200` is typed through `#[Response(200, ...)]` for the same reason as
     * `transcript()`; the type string mirrors `ScoringSerializer::toArray()`'s
     * `@return` shape.
     */
    #[Response(200, description: 'The BARS competency scoring: per-competency score and reliability, the three anchor-scored behaviors, and the scoring run\'s framework/model/prompt version triplet.', type: 'array{interview_id: string, status: string, competencies: array<string, array{score: float|null, reliability: float, behaviors: list<array{indicator: string, score: int, explanation: string, excerpts: list<string>, unassessable_reason: string|null}>, unscorable_reason: string|null}>, framework_version: string, model_version: string, prompt_version: string, evaluated_at: string}')]
    #[Response(409, description: 'Scoring not ready.', type: Problem::PROBLEM_SHAPE)]
    public function scoring(Request $request, string $interview): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $participant = $this->resolveParticipant($interview, $organization);

        if ($participant === null) {
            abort(404);
        }

        $notReady = $this->guardReady($request, $participant, self::SCORING_READY_STATUSES, 'scoring_not_ready', 'Scoring not ready');

        if ($notReady !== null) {
            return $notReady;
        }

        return PublicApiJson::response((new ScoringSerializer)->toArray($participant));
    }

    /**
     * List interview events.
     *
     * Returns the events of one interview in cursor-paginated pages, oldest first. This is the
     * one list ordered ascending, by the time each event occurred, rather than newest first.
     *
     * @response array{data: list<array{id: string, type: string, occurred_at: string, data: array<string, mixed>|null}>, next_cursor: string|null, has_more: bool}
     *
     * @internal
     * Internal notes, not published (Scramble exports docblock prose as public text):
     * `GET /v1/interviews/{id}/events`, using
     * `App\Support\PublicApi\CursorPage` in its ascending form (the one
     * documented exception to `created_at desc`). Every `InterviewEvent` row carries
     * a `public_id` (`evt_`).
     * `cursor`/`limit` carry explicit `#[QueryParameter]` attributes:
     * `CursorPage::paginateAscending()` reads them from `$request` outside this
     * method's body, so Scramble's static analysis does not detect them.
     */
    #[IgnoreResponse(422)]
    #[Response(400, description: 'Malformed query parameter.', type: Problem::PROBLEM_SHAPE)]
    #[QueryParameter('cursor', description: 'Opaque pagination cursor from a previous page\'s next_cursor. Omit for the first page. A present but malformed value answers 400 invalid_cursor.', type: 'string')]
    #[QueryParameter('limit', description: 'Page size, 1-100 (default 25). Out of range answers 400 validation_failed.', type: 'integer')]
    public function events(Request $request, string $interview): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $participant = $this->resolveParticipant($interview, $organization);

        if ($participant === null) {
            abort(404);
        }

        $query = InterviewEvent::where('participant_id', $participant->id);

        // Ordered by `occurred_at`, the event's logical time, which may predate `created_at`.
        $rawPage = CursorPage::paginateAscending(
            $query,
            $request,
            fn (InterviewEvent $event): array => array_filter([
                'id' => PublicId::encode($event),
                'type' => $event->type,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                // `data` is an object in the contract, never null: omit the key for an event with no payload.
                'data' => $event->data,
            ], fn (mixed $value): bool => $value !== null),
            column: 'occurred_at',
        );

        return PublicApiJson::response($rawPage);
    }

    /**
     * @param  list<InterviewStatus>  $readyStatuses
     * @param  'transcript_not_ready'|'scoring_not_ready'  $code
     */
    private function guardReady(Request $request, Participant $participant, array $readyStatuses, string $code, string $title): ?JsonResponse
    {
        $status = InterviewStatus::fromStored($participant->status);

        if (in_array($status, $readyStatuses, true)) {
            return null;
        }

        return Problem::make($request, 409, $code, $title);
    }

    private function resolveOrganization(): Organization
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        // Resolved explicitly rather than trusted: the tenant middleware already answers 401 first.
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

        // Deliberately not `withTrashed()`: a soft-deleted project answers 404 like an unknown one, since a new
        // enrolment has no existing data to preserve and the active-project gate would refuse it anyway.
        return Project::query()->wherePublicId($bareId)->first();
    }

    private function resolveParticipant(string $rawId, Organization $organization, bool $expandProject = false): ?Participant
    {
        $bareId = PublicId::decode($rawId, Participant::publicIdPrefix());

        if ($bareId === null) {
            return null;
        }

        // Mode-scoped: a test key requesting a live interview (or the reverse) answers 404, never revealing the row.
        $mode = app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live;

        // Always eager-load the project, trashed included, as `index()` does.
        return Participant::where('organization_id', $organization->id)
            ->where('mode', $mode)
            ->wherePublicId($bareId)
            ->with(self::projectEagerLoad($expandProject))
            ->first();
    }

    /**
     * The `project` eager-load constraint `index()` and
     * `resolveParticipant()` each built independently before — always
     * `withTrashed()`-equivalent (see `projectIncludingTrashed()`'s own
     * docblock),
     * with either the FULL nested relations `?expand=project` needs or the
     * column-restricted `id`/`public_id`-only shape the unexpanded case
     * reads.
     *
     * @return array{project: \Closure(Relation<*, *, *>): Builder<*>}
     */
    private static function projectEagerLoad(bool $expandProject): array
    {
        return ['project' => fn (Relation $relation): Builder => $expandProject
            ? self::projectIncludingTrashed($relation)->with(['frameworkVersion', 'avatarTemplate', 'competencies'])
            : self::projectIncludingTrashed($relation)->select(['id', 'public_id'])];
    }

    /**
     * Delegates to `App\Support\PublicApi\IncludeTrashed::forRelation()`
     * — extracted there once `WebhookDeliveryController` needed the identical mechanics for its
     * own `project` eager-load, rather than a second ad hoc copy of this
     * method's own reasoning. See that class's own docblock for the full
     * "why `Relation`, not `BelongsTo`" rationale.
     *
     * @param  Relation<*, *, *>  $relation
     * @return Builder<*>
     */
    private static function projectIncludingTrashed(Relation $relation): Builder
    {
        return IncludeTrashed::forRelation($relation);
    }

    private function validateFilterFormats(Request $request): void
    {
        // An empty filter is "not provided": see `QueryFilters`.
        $validator = Validator::make(QueryFilters::provided($request, ['metadata']), [
            'status' => ['sometimes', 'string', Rule::in(InterviewStatus::values())],
            'project_id' => ['sometimes', 'string'],
            'email' => ['sometimes', 'string', 'email'],
            'candidate_ref' => ['sometimes', 'string', 'max:255'],
            // External reference filters share the create body's bounds, but `integer` is not strict because
            // query values arrive as strings. A malformed value is a 400, not a 422.
            'external_id' => ['sometimes', 'integer', 'min:1', 'max:'.ExternalReference::MAX_EXTERNAL_ID],
            'source' => ['sometimes', 'string', 'max:'.ExternalReference::SOURCE_MAX_LENGTH],
            // Strict ISO 8601 via `Iso8601DateTime`; plain `date` accepted relative phrases and offset-less values.
            'created_after' => ['sometimes', new Iso8601DateTime],
            'created_before' => ['sometimes', new Iso8601DateTime],
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
