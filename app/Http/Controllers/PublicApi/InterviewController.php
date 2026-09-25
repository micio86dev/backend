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
use App\Support\PublicApi\ApiMode;
use App\Support\PublicApi\CursorPage;
use App\Support\PublicApi\Expand;
use App\Support\PublicApi\HostedInterviewUrlComposer;
use App\Support\PublicApi\IncludeTrashed;
use App\Support\PublicApi\InterviewStatus;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicApiJson;
use App\Support\PublicApi\PublicId;
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

    /**
     * SPEC.md §3.3: transcript/answers readable from `under_evaluation`
     * onward (G-15: an `error` interview stays `409 transcript_not_ready` —
     * `Error` is deliberately absent here).
     *
     * @var list<InterviewStatus>
     */
    private const TRANSCRIPT_READY_STATUSES = [InterviewStatus::UnderEvaluation, InterviewStatus::Completed];

    /**
     * SPEC.md §3.3: scoring readable only once `completed`.
     *
     * @var list<InterviewStatus>
     */
    private const SCORING_READY_STATUSES = [InterviewStatus::Completed];

    public function __construct(
        private readonly EnrolCandidate $enrolCandidate,
        private readonly HostedInterviewUrlComposer $hostedInterviewUrlComposer,
    ) {}

    /**
     * `interview` documented as the `PublicInterview` object it always is
     * (step 5 review follow-up, Part B item 1) — `response()->json([...])`'s
     * own inferred type only ever saw `InterviewResource::resolve()`'s
     * loose `array<string, mixed>` return type, so the exported spec
     * previously carried an untyped array here instead of a `$ref`.
     * `#[Response(201, ...)]` (not a bare `@response` PHPDoc tag) — the
     * PHPDoc form replaces Scramble's ENTIRE inferred response, collapsing
     * the real `201` this method actually returns down to a default `200`;
     * the attribute form names the status explicitly and overlays onto
     * the response Scramble already inferred at it, leaving the other
     * auto-inferred statuses (`404`, `409`, `422`) untouched. `metadata`'s
     * accepted shape (Part B item 3) is corrected at its source —
     * `App\Rules\PublicApi\Metadata::docs()` — rather than here, so
     * `CreateInterviewRequest`'s own named schema carries the fix
     * directly instead of an `allOf` overlay fighting the same property's
     * wrong type inside it.
     */
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

    /**
     * `data[]` documented as a list of `PublicInterview` objects, and
     * `next_cursor` as the nullable string it genuinely is (step 5 review
     * follow-up, Part B item 2) — `CursorPage::paginate()`'s own
     * `next_cursor: string|null` PHPDoc did not survive being returned
     * through `response()->json($rawPage)`, so the exported spec
     * previously typed it as a non-nullable `string` and `data[]`'s items
     * as untyped. `#[IgnoreResponse]`/`#[Response(400, ...)]` (Part B item
     * 6) replace the incorrect auto-inferred `422 {message, errors}` this
     * method's own `QueryValidationException` throw produced — see
     * `Problem::PROBLEM_SHAPE`'s own docblock (step 6 review follow-up,
     * Part A item 6: now shared from `App\Support\PublicApi\Problem`
     * rather than a copy of the constant declared on this class).
     *
     * `created_after`/`created_before` documented explicitly (step 6 review
     * follow-up, Part A item 8) — without a `#[QueryParameter]` override,
     * Scramble's own inference picked up the nearest preceding CODE COMMENT
     * above `$request->query('created_after')` below as this parameter's
     * description (an internal implementation note about
     * `validateFilterFormats()`/`Validator::validated()`, meaningless to an
     * API consumer, and `created_before` got no description at all). These
     * two attributes describe the accepted FORMAT and the `400` a caller
     * actually gets on a malformed value, the same contract
     * `App\Rules\PublicApi\Iso8601DateTime` enforces.
     *
     * @response array{data: list<\App\Http\Resources\PublicApi\InterviewResource>, next_cursor: string|null, has_more: bool}
     */
    #[IgnoreResponse(422)]
    #[Response(400, description: 'Malformed query parameter.', type: Problem::PROBLEM_SHAPE)]
    #[QueryParameter('created_after', description: 'Inclusive lower bound on created_at. Strict ISO 8601 date-time, UTC (Z) or a numeric offset, e.g. 2026-01-01T00:00:00Z. An invalid or non-ISO-8601 value answers 400 validation_failed.', type: 'string')]
    #[QueryParameter('created_before', description: 'Exclusive upper bound on created_at. Strict ISO 8601 date-time, UTC (Z) or a numeric offset, e.g. 2026-01-01T00:00:00Z. An invalid or non-ISO-8601 value answers 400 validation_failed.', type: 'string')]
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

        // mode-scoped (G-51): a beai_test_ key must never list a live
        // interview, and vice versa — SPEC.md §3.7's "must never read or
        // write live data" applied here the same way
        // `ExportController`/`UsageController` already scope every other
        // /v1 read by the requesting key's own mode.
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
            // A malformed/mismatched project_id filter matches NOTHING
            // rather than 400 — SPEC.md §3.2 treats an id filter like a
            // path parameter (mismatched prefix → no match), never a
            // format error on a list operation.
            //
            // withTrashed() (step 5 review follow-up, item 5): every OTHER
            // read on this controller already resolves a participant's
            // project `withTrashed()` (see `projectIncludingTrashed()`'s own
            // docblock) — a project an admin later soft-deletes must not
            // make interviews under it unfindable by THIS filter either,
            // consistent with the same "the enrolment is the calling
            // system's data" reasoning. Without it, `Project::query()`'s
            // default `SoftDeletingScope` made a trashed project's public id
            // resolve to no internal id at all, so the filter silently
            // matched NOTHING instead of the participants that are
            // genuinely there.
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

        // Normalised to a CarbonImmutable before the comparison (step 5
        // review follow-up, item 6) — never the raw query string. By the
        // time this runs, `validateFilterFormats()` above has already
        // confirmed the value matches `Iso8601DateTime`'s own strict
        // format set, so `parse()` is not expected to fail here; it is
        // still checked defensively rather than trusted blindly, matching
        // this method's own "never Validator::validated()" discipline for
        // filter VALUES.
        $createdAfter = $request->query('created_after');
        if (is_string($createdAfter) && $createdAfter !== '') {
            $parsedCreatedAfter = Iso8601DateTime::parse($createdAfter);

            // ->utc() (step 6 review follow-up, Part A item 3): a numeric-
            // offset value (e.g. `+02:00`) parses into a CarbonImmutable
            // whose OWN timezone carries that offset, not UTC. Binding it
            // as-is lets the query builder format the WALL-CLOCK digits in
            // that offset into the SQL parameter, which Postgres then reads
            // back in the connection's session timezone — silently
            // comparing against the wrong instant whenever that session
            // timezone is not the same offset. Converting to UTC first
            // makes the bound value the same absolute instant regardless of
            // which of the four accepted shapes the caller used.
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
        // reads `id`/`public_id`, so it stays column-restricted. One shared
        // method with `resolveParticipant()` (step 5 review follow-up,
        // item 9) — see `self::projectEagerLoad()`'s own docblock.
        $query->with(self::projectEagerLoad($expandProject));

        // Fetch the page's raw Participant models first (identity map),
        // batch-compute progress AND recording readiness for the WHOLE page
        // in two calls (three queries total: two for progress, one for
        // recording_ready — gga finding 4, gga review step 6 follow-up
        // finding 5) — THEN serialize each row with its own precomputed
        // slice, never one query per row.
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

    public function show(Request $request, string $interview): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $expand = Expand::parse($request, self::EXPANDABLE);
        $expandProject = in_array('project', $expand, true);

        $participant = $this->resolveParticipant($interview, $organization, $expandProject);

        if ($participant === null) {
            abort(404);
        }

        // progressForMany() for this ONE participant (step 5 review
        // follow-up, item 9) — the SAME batched query `index()` runs for a
        // whole page, called here with a single-element list, so there is
        // exactly ONE code path computing progress, not a second
        // `getInterview`-only implementation that could silently drift
        // from it. See `InterviewSerializer::progress()`'s own docblock:
        // it now delegates here too, so a caller that omits `$progress`
        // (e.g. `store()`, a brand-new enrolment with nothing to report
        // yet) still converges on this one implementation.
        $progress = InterviewSerializer::progressForMany([$participant])[$participant->id] ?? [];
        $recordingReady = InterviewSerializer::recordingReadyForMany([$participant])[$participant->id] ?? false;

        return InterviewResource::make($participant, $expandProject, $progress, $recordingReady)->response();
    }

    // `@response` documented explicitly via `#[Response(200, ...)]` below
    // (step 6 review follow-up, finding 14; step 7 review follow-up,
    // rewritten as an attribute rather than a bare `@response` PHPDoc tag)
    // — `PublicApiJson::response(TranscriptSerializer::toArray(...))` did
    // not survive Scramble's own inference as a typed schema, and the
    // exported spec previously carried a bare, untyped `object` for this
    // endpoint's `200`. The type string mirrors `TranscriptSerializer::
    // toArray()`'s own `@return` array-shape docblock verbatim, the same
    // "one shape, never a second, independently-typed copy" discipline
    // `index()`'s own `@response` tag above already applies. Moved out of
    // the docblock (step 7 review follow-up, Part A item 2): the PHPDoc
    // form's prose was exported verbatim as this operation's public
    // `description`, leaking internal review narration to API consumers —
    // an attribute's `description:` argument is the caller-facing text
    // Scramble actually publishes for a specific response.
    /**
     * `GET /v1/interviews/{id}/transcript` — SPEC.md §3.3, gate: status
     * `under_evaluation` or `completed`, else `409 transcript_not_ready`
     * (`error` included — G-15).
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

    // `@response` documented explicitly via `#[Response(200, ...)]` below,
    // for the same reason `transcript()`'s own comment states (step 6
    // review follow-up, finding 14; step 7 review follow-up, moved out of
    // the docblock for the same reason) — this method wraps
    // `AnswersSerializer::toArray()`'s own `@return` list shape in the
    // `{interview_id, answers}` envelope SPEC.md §3.3 describes; the
    // exported spec previously carried a bare, untyped `object` for this
    // endpoint's `200`.
    /**
     * `GET /v1/interviews/{id}/answers` — SPEC.md §3.3, same read gate as
     * the transcript.
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

    // `@response` documented explicitly via `#[Response(200, ...)]` below
    // — found alongside `transcript()`/`answers()` carrying the identical
    // bare-`object` `200` export defect (step 6 review follow-up, finding
    // 14 named the first two; this one exhibits the same root cause and is
    // fixed for the same reason, not left half-done; step 7 review
    // follow-up moved all three out of the docblock for the same reason).
    // The type string mirrors `ScoringSerializer::toArray()`'s own
    // `@return` array-shape docblock verbatim.
    /**
     * `GET /v1/interviews/{id}/scoring` — SPEC.md §3.3, gate: status
     * `completed` only, else `409 scoring_not_ready`.
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
     * `GET /v1/interviews/{id}/events` — SPEC.md §3.3, `App\Support\
     * PublicApi\CursorPage` in its ASCENDING form (G-12: the one documented
     * exception to `created_at desc`). `InterviewEvent.public_id` (`evt_`)
     * is what every real row already carries — see that model's own
     * docblock; no participant special-cases its absence.
     *
     * `cursor`/`limit` documented explicitly (step 6 review follow-up,
     * finding 14) — `CursorPage::paginateAscending()` reads both directly
     * off `$request` from INSIDE `App\Support\PublicApi\CursorPage`, one
     * call frame away from this method's own body, which is why
     * Scramble's own static-analysis auto-detection (which scans a
     * controller method's own body for `$request->query()`/`$request->
     * integer()` calls) never picked them up — the exported spec
     * previously documented only the `interview` path parameter for this
     * operation.
     *
     * @response array{data: list<array{id: string, type: string, occurred_at: string, data: array<string, mixed>|null}>, next_cursor: string|null, has_more: bool}
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

        // occurred_at, not created_at (G-12) — the event's own LOGICAL
        // timestamp, which the owning migration documents as possibly
        // predating created_at for a queued writer; see CursorPage::
        // paginateAscending()'s own docblock for the $column parameter.
        $rawPage = CursorPage::paginateAscending(
            $query,
            $request,
            fn (InterviewEvent $event): array => array_filter([
                'id' => PublicId::encode($event),
                'type' => $event->type,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                // `data` is declared `type: object` in the contract — never
                // nullable — and is not in `InterviewEvent`'s own `required`
                // list. `array_filter()` (strict `!== null`, never the
                // default falsy-value filter) OMITS the key entirely for an
                // event recorded with no payload, rather than sending a
                // `data: null` no `object`-typed schema without an explicit
                // `"null"` member accepts.
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

        // mode-scoped (G-51): the single choke point `show()`, `transcript()`,
        // `answers()`, `scoring()` and `events()` all resolve their
        // participant through — a beai_test_ key requesting a LIVE
        // interview id (or vice versa) must answer 404, never leak that
        // the row exists under the other mode. Same reasoning as
        // `index()`'s own mode filter above.
        $mode = app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live;

        // Same "always eager-load project, withTrashed()" discipline as
        // index() (gga findings 1 and 4) — a single row here, so the cost
        // is trivial either way, but `InterviewSerializer::project()`'s
        // eager-loaded branch stays the one path every `/v1` read actually
        // exercises.
        return Participant::where('organization_id', $organization->id)
            ->where('mode', $mode)
            ->wherePublicId($bareId)
            ->with(self::projectEagerLoad($expandProject))
            ->first();
    }

    /**
     * The `project` eager-load constraint `index()` and
     * `resolveParticipant()` each built independently before (step 5
     * review follow-up, item 9) — always `withTrashed()`-equivalent (gga
     * round 3 finding 1, see `projectIncludingTrashed()`'s own docblock),
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
     * (gga pre-commit follow-up, step 7 finding 2) — extracted there once
     * `WebhookDeliveryController` needed the identical mechanics for its
     * own `project` eager-load, rather than a second ad hoc copy of this
     * method's own reasoning. See that class's own docblock for the full
     * "why `Relation`, not `BelongsTo`" rationale (gga round 3 finding 1).
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
        $validator = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', Rule::in(InterviewStatus::values())],
            'project_id' => ['sometimes', 'string'],
            'email' => ['sometimes', 'string', 'email'],
            'candidate_ref' => ['sometimes', 'string', 'max:255'],
            // Strict ISO 8601 (step 5 review follow-up, item 6) — see
            // `Iso8601DateTime`'s own docblock for why the plain `'date'`
            // rule this replaces was too permissive (relative phrases, a
            // bare date with no time, a date-time with no timezone all
            // passed it).
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
