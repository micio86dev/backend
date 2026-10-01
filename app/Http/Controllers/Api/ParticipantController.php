<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\EvaluationResource;
use App\Http\Resources\Admin\ParticipantDetailResource;
use App\Http\Resources\Admin\ParticipantResource;
use App\Http\Resources\Admin\TranscriptResource;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Services\Admin\AdminTranscriptSerializer;
use App\Support\Admin\AdminParticipantReader;
use App\Support\Admin\ParticipantReadScope;
use App\Support\Admin\ReusableLinkOrigin;
use App\Support\Participant\ExternalReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin ParticipantController (C11 — Admin Dashboards, PR A3, D5).
 *
 * Every action reaches a Participant exclusively through
 * AdminParticipantReader (D1) — index via listQuery(), the rest via
 * read($id, $scope). Neither this class nor any other file under
 * app/Http/Controllers/Api may contain a bare static call on the Participant
 * model directly — enforced by AdminTenancySafetyArchTest (task 2.3b). IDs are resolved
 * manually, never via route-model binding, per ProjectController.php:23-28's
 * documented reason (SubstituteBindings runs before TenantContext).
 *
 * Routes (all under auth:api + TenantContext, routes/api.php):
 *   GET /participants                    — index (RBAC only, Summary scope)
 *   GET /participants/{id}                — show (RBAC only, Summary scope)
 *   GET /participants/{id}/transcript     — transcript (lifecycle >= in_corso, or errore)
 *   GET /participants/{id}/evaluation     — evaluation (lifecycle === completato)
 *
 * REQ: Admin Read Endpoint Surface, Cross-Tenant Isolation on Every Admin
 *      Read Endpoint (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */
final class ParticipantController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MIN_PER_PAGE = 1;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AdminParticipantReader $reader,
        private readonly AdminTranscriptSerializer $transcriptSerializer,
        private readonly AdminEvaluationSerializer $evaluationSerializer,
    ) {}

    // Internal notes, not published (Scramble exports docblock prose as public text):
    // GET /api/participants
    //
    // Server-paginated (D5 — a fresh authorized query per page, never
    // fetch-all + client filter). Sort is fixed (created_at desc, id desc):
    // no client-specified sort column reaches the query builder.
    //
    // `q` matches `candidate_ref`, `display_name` and `source` as a
    // case-insensitive substring, taking `%`, `_` and `\` literally, and the
    // candidate's `external_id` by exact equality, only when the trimmed term
    // is a whole number from 1 to 9007199254740991.
    /**
     * List participants.
     *
     * Paginated on the server, newest first. The `q` filter matches `candidate_ref`, `display_name` and
     * `source` as a case-insensitive substring, and the candidate's `external_id` by exact equality when
     * the trimmed term is a whole number from 1 to 9007199254740991.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->reader->listQuery();

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->input('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('q')) {
            $term = '%'.$this->escapeLike($request->string('q')->value()).'%';
            // Null unless the trimmed term is all ASCII digits within 1..2^53-1:
            // a non-numeric or over-range term must never reach the BIGINT
            // comparison below (Postgres would raise 22P02 / 22003 -> 500).
            $externalId = ExternalReference::parseExternalIdTerm($request->string('q')->trim()->value());

            // One OR group nested in a single where(): it can never escape the
            // `organization_id` scope `AdminParticipantReader::listQuery()` and
            // the `status`/`project_id` filters above already applied.
            $query->where(function ($sub) use ($term, $externalId): void {
                $sub->where('candidate_ref', 'ilike', $term)
                    ->orWhere('display_name', 'ilike', $term)
                    ->orWhere('source', 'ilike', $term);

                if ($externalId !== null) {
                    $sub->orWhere('external_id', $externalId);
                }
            });
        }

        $perPage = max(
            self::MIN_PER_PAGE,
            min(self::MAX_PER_PAGE, (int) $request->input('per_page', self::DEFAULT_PER_PAGE))
        );

        $participants = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ParticipantResource::collection($participants);
    }

    /**
     * Makes a search term literal inside a LIKE pattern. Postgres treats
     * backslash as the default LIKE escape character, so escaping it first and
     * then `%` and `_` leaves no character in the term able to act as a
     * wildcard — a search for `%` or `_` used to match every row.
     */
    private function escapeLike(string $term): string
    {
        return addcslashes($term, '\\%_');
    }

    // Internal notes, not published (Scramble exports docblock prose as public text):
    // GET /api/participants/{id}
    //
    // Summary scope (D2) — RBAC only, readable regardless of lifecycle status.
    /**
     * Get a participant.
     *
     * Readable whatever the participant's lifecycle status.
     */
    public function show(int $id): ParticipantDetailResource
    {
        $participant = $this->reader->read($id, ParticipantReadScope::Summary);

        // Only the detail resource shows the reusable link origin, so it is
        // loaded here and not in the reader, which also serves the transcript
        // and evaluation reads.
        $participant->loadMissing(ReusableLinkOrigin::EAGER_LOAD);

        return new ParticipantDetailResource($participant);
    }

    // Internal notes, not published (Scramble exports docblock prose as public text):
    // GET /api/participants/{id}/transcript
    //
    // Transcript scope (D2) — requires lifecycle >= in_corso, OR errore
    // (operator-participant-visibility D1); a pre-threshold status (in_attesa)
    // raises LifecycleNotReadyException -> 409 (D4).
    /**
     * Get a participant's transcript.
     *
     * Available once the interview is in progress, and for an interview that ended in error; before
     * that the response is `409`.
     */
    public function transcript(int $id): TranscriptResource
    {
        $participant = $this->reader->read($id, ParticipantReadScope::Transcript);

        return new TranscriptResource($this->transcriptSerializer->serialize($participant));
    }

    // Internal notes, not published (Scramble exports docblock prose as public text):
    // GET /api/participants/{id}/evaluation
    //
    // Evaluation scope (D2) — requires lifecycle === completato; anything
    // else raises LifecycleNotReadyException -> 409 (D4).
    /**
     * Get a participant's evaluation.
     *
     * Available only once the interview is completed; otherwise the response is `409`.
     */
    public function evaluation(int $id): EvaluationResource
    {
        $participant = $this->reader->read($id, ParticipantReadScope::Evaluation);

        return new EvaluationResource(
            $this->evaluationSerializer->serialize($participant),
            $this->evaluationSerializer->meta($participant),
            $this->evaluationSerializer->auditMeta($participant),
        );
    }
}
