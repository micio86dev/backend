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
 *   GET /participants/{id}/transcript     — transcript (lifecycle >= in_valutazione)
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

    /**
     * GET /api/participants
     *
     * Server-paginated (D5 — a fresh authorized query per page, never
     * fetch-all + client filter). Sort is fixed (created_at desc, id desc):
     * no client-specified sort column reaches the query builder.
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
            $term = '%'.$request->string('q')->value().'%';
            $query->where(function ($sub) use ($term): void {
                $sub->where('candidate_ref', 'like', $term)
                    ->orWhere('display_name', 'like', $term);
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
     * GET /api/participants/{id}
     *
     * Summary scope (D2) — RBAC only, readable regardless of lifecycle status.
     */
    public function show(int $id): ParticipantDetailResource
    {
        $participant = $this->reader->read($id, ParticipantReadScope::Summary);

        return new ParticipantDetailResource($participant);
    }

    /**
     * GET /api/participants/{id}/transcript
     *
     * Transcript scope (D2) — requires lifecycle >= in_valutazione; a
     * pre-threshold status raises LifecycleNotReadyException -> 409 (D4).
     */
    public function transcript(int $id): TranscriptResource
    {
        $participant = $this->reader->read($id, ParticipantReadScope::Transcript);

        return new TranscriptResource($this->transcriptSerializer->serialize($participant));
    }

    /**
     * GET /api/participants/{id}/evaluation
     *
     * Evaluation scope (D2) — requires lifecycle === completato; anything
     * else raises LifecycleNotReadyException -> 409 (D4).
     */
    public function evaluation(int $id): EvaluationResource
    {
        $participant = $this->reader->read($id, ParticipantReadScope::Evaluation);

        return new EvaluationResource($this->evaluationSerializer->serialize($participant));
    }
}
