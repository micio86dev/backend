<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Actions\Interview\BuildInterviewOutline;
use App\Enums\AssessmentType;
use App\Http\Controllers\Controller;
use App\Models\Participant;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candidate OutlineController (live-interview-call-chrome D1).
 *
 * Route: GET /api/candidate/interview/outline
 * Auth: auth:api-candidate + TenantContextCandidate + ParticipantStatusGuard (the nested `interview` group)
 *
 * A read: it accepts no participant, project or session identifier (the participant comes only from
 * the token) and writes nothing.
 *
 * REQ: interview-session "GET /candidate/interview/outline"
 */
final class OutlineController extends Controller
{
    /**
     * The candidate's interview path.
     *
     * Returns the interview as the candidate sees it while it runs: the assessment type, the role, the
     * interviewer's and the candidate's own display names, and the competencies in order, each with its
     * name, its 1-based position, how many scripted questions it holds and whether it is done, current,
     * upcoming or queued to be repeated. Names are in the project language. No competency code or
     * identifier is returned. `422 assessment_type_not_supported` means the project's assessment type is
     * not supported, and `422 project_not_found` means the project no longer exists.
     *
     * @internal
     * Internal notes, not published (Scramble exports docblock prose as public text):
     * The order and the open/closed rule are `CompetencyProgress`, shared with `POST /start`, so `current`
     * is always the competency the next `/start` serves. `ParticipantStatusGuard` refuses terminal
     * participants before this method runs. Not part of `/v1`, not in `ParticipantResource`.
     */
    // `#[Response]` attributes, not `@scramble-return`: the PHPDoc form replaces the whole inferred
    // response set, which would hide the refusals documented below.
    #[ApiResponse(200, description: 'The interview outline.', type: "array{assessment_type: 'standard'|'potential', role_name: string|null, avatar_name: string|null, candidate_name: string|null, primary_total_all: int, competencies: list<array{name: string|null, ordinal: int, status: 'done'|'current'|'upcoming'|'retry', primary_total: int}>}")]
    #[ApiResponse(403, description: 'The participant is no longer in an active interview (completed or errored).', type: 'array{message: string}')]
    #[ApiResponse(422, description: 'The project no longer exists (`project_not_found`) or its assessment type is not supported (`assessment_type_not_supported`).', type: "array{error: 'project_not_found'|'assessment_type_not_supported'}")]
    public function show(Request $request, BuildInterviewOutline $outline): JsonResponse
    {
        /** @var Participant $participant */
        $participant = $request->user('api-candidate');

        $project = $participant->project;

        if ($project === null) {
            return response()->json(['error' => 'project_not_found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $storedType = $project->getAttributes()['assessment_type'] ?? null;
        $type = is_string($storedType) ? AssessmentType::tryFrom($storedType) : null;

        if ($type === null) {
            return response()->json(['error' => 'assessment_type_not_supported'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($outline->handle($participant, $project, $type));
    }
}
