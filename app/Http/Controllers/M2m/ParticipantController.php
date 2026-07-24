<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParticipantResource;
use App\Models\ApiClient;
use App\Models\Participant;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            'display_name' => ['required', 'string', 'max:255'],
            'role_code' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', 'string'],
        ]);

        // Resolve project SCOPED to caller org (cross-org → 404).
        $project = Project::where('organization_id', $clientOrgId)
            ->findOrFail($validated['project_id']);

        // Create or find existing participant — organization_id from project (NOT from request).
        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $project->organization_id,  // server-side, NOT from request
            'project_id' => $project->id,
            'candidate_ref' => $validated['candidate_ref'],
            'display_name' => $validated['display_name'],
            'role_code' => $validated['role_code'] ?? null,
            'language' => $validated['language'] ?? null,
            'status' => $validated['status'] ?? 'in_attesa',
        ]);
        $participant->save();

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
