<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParticipantResource;
use App\Models\Participant;
use Illuminate\Http\Request;

/**
 * Candidate SessionController (C6 — Participant + SSO Ingress).
 *
 * Returns the authenticated candidate's session context.
 *
 * Route: GET /api/candidate/session
 * Auth: auth:api-candidate + TenantContextCandidate
 *
 * Response includes:
 * - participant (id, candidate_ref, status, role_code, language)
 * - project config (id, role_code, language, assessment_type)
 * - exit_redirect_url
 *
 * Security: participant is loaded via auth:api-candidate guard.
 * No internal-id leaks (organization_id excluded from candidate-facing resource).
 *
 * REQ: Candidate Session Endpoint
 */
final class SessionController extends Controller
{
    /**
     * Return the authenticated candidate's session data.
     *
     * GET /api/candidate/session
     * Auth: auth:api-candidate + TenantContextCandidate
     */
    public function show(Request $request): ParticipantResource
    {
        /** @var Participant $participant */
        $participant = $request->user('api-candidate');

        // Eager-load project for the resource to include project context + exit_redirect_url.
        $participant->load('project');

        return new ParticipantResource($participant);
    }
}
