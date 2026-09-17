<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParticipantResource;
use App\Models\ApiClient;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Project\ProjectInterviewability;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * M2M ParticipantController (C6 — Participant + SSO Ingress).
 *
 * Admin-only (M2M caller) participant management.
 *
 * Routes (all under /api/m2m, auth:api-m2m):
 *   POST /participants          (participants:create)
 *   GET  /participants          (participants:read)
 *   GET  /participants/{id}     (participants:read)
 *
 * Security invariants:
 * - store: project resolved SCOPED to caller org (cross-org → 404).
 * - store: organization_id set from $project->organization_id via forceFill (NOT from request).
 * - index/show: manual ->where('organization_id', $orgId) filter (mirrors ApiClientController).
 * - No show for cross-org participant → 404.
 *
 * REQ: M2M Participant CRUD
 */
final class ParticipantController extends Controller
{
    public function __construct(
        private readonly ProjectInterviewability $projectInterviewability,
    ) {}

    /**
     * Create a new participant for a project in the caller's org.
     *
     * POST /api/m2m/participants
     * Auth: auth:api-m2m + ability:participants:create
     */
    public function store(Request $request): JsonResponse
    {
        /** @var ApiClient $client */
        $client = $request->user('api-m2m');
        $clientOrgId = $client->organization_id;

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'candidate_ref' => ['required', 'string', 'max:255'],
            // Required: the email IS the candidate's identity across projects
            // and organizations (CLAUDE.md ruling 8, reversed 2026-09-01), and
            // the column is NOT NULL. There is no legacy contract to keep —
            // this product is greenfield by ruling.
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'role_code' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        // Resolve project SCOPED to caller org (cross-org → 404).
        $project = Project::where('organization_id', $clientOrgId)
            ->findOrFail((int) $validated['project_id']);

        // D5/D6 (framework-catalogue-authoring PR6) — before ANY participant
        // row is written: the earliest point the calling system can be told,
        // strictly kinder than failing at the candidate's door. `evaluate()`
        // computes both the refusal decision and the competency list from
        // ONE query, not two. `candidate_ref` is echoed BYTE-FOR-BYTE —
        // never normalised, trimmed or re-cased — because it is the calling
        // system's only correlation handle for a request that produced no
        // participant row at all.
        //
        // Z10 (REQUIRED BEFORE ARCHIVE) — `evaluateForCandidate()`, for
        // symmetry with the other two mint ingresses (`EntryLinkController`,
        // `SsoLinkController`): exempts a candidate who already has an
        // `InterviewSession`. In practice this endpoint always CREATES a new
        // `Participant` row, so a `candidate_ref` that already has a session
        // also already has a participant row and would fail the
        // `(project_id, candidate_ref)` unique constraint below regardless
        // of interviewability — this exemption does not change that
        // pre-existing, separate behavior, it only stops interviewability
        // from being the FIRST thing to (incorrectly) refuse a request this
        // endpoint was never going to satisfy anyway for an unrelated reason.
        $interviewability = $this->projectInterviewability->evaluateForCandidate($project, $validated['candidate_ref']);
        if (! $interviewability['interviewable']) {
            return response()->json([
                'error' => 'PROJECT_NOT_INTERVIEWABLE',
                'competency_codes' => $interviewability['unsatisfied_competency_codes'],
                'candidate_ref' => $validated['candidate_ref'],
            ], 422);
        }

        // Create participant — organization_id from project (NOT from
        // request), status hardcoded (gga review finding, blocking): the
        // candidate lifecycle (in_attesa → in_corso → in_valutazione →
        // completato | errore) is a binding domain constraint, and
        // `Participant::booted()`'s own transition guard only listens to
        // `updating`, never `creating` — a caller-supplied `status` here
        // would have reached the row unchecked, letting an M2M caller mint
        // a participant already past the gates that status exists to
        // enforce. C6 only ever creates `in_attesa` (see that guard's own
        // docblock); this endpoint does the same.
        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $project->organization_id,  // server-side, NOT from request
            'project_id' => $project->id,
            'candidate_ref' => $validated['candidate_ref'],
            'display_name' => $validated['display_name'],
            'email' => $validated['email'],
            'role_code' => $validated['role_code'] ?? null,
            'language' => $validated['language'] ?? null,
            'status' => 'in_attesa',
        ]);

        // R3-test-pins-500 (framework-catalogue-authoring, REQUIRED BEFORE
        // ARCHIVE): this endpoint has no find-or-create path, so a
        // `candidate_ref` or `email` that already has a row on this project
        // — most often a genuine concurrent-request race, since the
        // interviewability check above already ran — loses to one of these
        // two unique constraints. Mapped to a clean 409, the same doctrine
        // `SsoLinkController::store()` already applies to its own
        // `EntryLinkRefused` conflicts, instead of an uncaught 500.
        //
        // `DB::transaction()`, not a bare `save()`: Postgres aborts the
        // WHOLE ambient transaction on any failed statement, not just the
        // one that failed — with no savepoint of its own, the 409 response
        // below would be correct but every later query in the SAME request
        // (or the same test's wrapping transaction) would fail with
        // "current transaction is aborted" for a reason that has nothing to
        // do with it. `DB::transaction()` opens a SAVEPOINT here, so only
        // this insert unwinds.
        try {
            DB::transaction(function () use ($participant): void {
                $participant->save();
            });
        } catch (QueryException $e) {
            $reason = match (true) {
                str_contains($e->getMessage(), 'participants_project_id_candidate_ref_unique') => 'duplicate_candidate_ref',
                str_contains($e->getMessage(), 'participants_project_id_email_unique') => 'duplicate_email',
                default => null,
            };

            if ($reason === null) {
                throw $e;
            }

            return response()->json([
                'message' => 'Conflict: a participant already exists for this project with this '.($reason === 'duplicate_email' ? 'email.' : 'candidate_ref.'),
                'reason' => $reason,
            ], 409);
        }

        return response()->json(new ParticipantResource($participant), 201);
    }

    /**
     * List participants for the caller's organization.
     *
     * GET /api/m2m/participants
     * Auth: auth:api-m2m + ability:participants:read
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var ApiClient $client */
        $client = $request->user('api-m2m');
        $orgId = $client->organization_id;

        // Manual org filter — mirrors ApiClientController::index pattern.
        $participants = Participant::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->paginate(20);

        return ParticipantResource::collection($participants);
    }

    /**
     * Show a specific participant (org-scoped).
     *
     * GET /api/m2m/participants/{id}
     * Auth: auth:api-m2m + ability:participants:read
     */
    public function show(Request $request, int $id): ParticipantResource
    {
        /** @var ApiClient $client */
        $client = $request->user('api-m2m');
        $orgId = $client->organization_id;

        // Manual org filter — cross-org → 404.
        $participant = Participant::where('organization_id', $orgId)
            ->findOrFail($id);

        return new ParticipantResource($participant);
    }
}
