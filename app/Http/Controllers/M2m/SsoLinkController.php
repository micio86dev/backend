<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Project;
use App\Support\Project\ProjectInterviewability;
use App\Support\Sso\EntryLinkMinter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SsoLinkController (C6 — Participant + SSO Ingress).
 *
 * Mints a short-lived sso-link JWT for a candidate ingress request.
 *
 * Route: POST /api/m2m/sso-link
 * Auth: auth:api-m2m + ability:sso_link:generate
 *
 * Flow:
 *   1. Resolve project SCOPED to the caller's organization (cross-org → 404).
 *   2. `ProjectInterviewability::evaluateForCandidate()` (framework-catalogue-
 *      authoring PR6, D5/D6; Z10) — 422 `PROJECT_NOT_INTERVIEWABLE` before the
 *      minter is ever reached, unless this candidate already has a session.
 *      **Response shape change from before PR6**:
 *      a project that is BOTH closed (entry gate) AND non-interviewable now
 *      answers `422 PROJECT_NOT_INTERVIEWABLE` here, where it previously
 *      reached `EntryLinkMinter::mint()` and answered `403 Access denied`
 *      instead — interviewability is checked strictly before the minter's
 *      own gates.
 *   3. Delegate the mint decision to `EntryLinkMinter::mint()` (entry gates,
 *      role_code inheritance/validation, terminal-status refusal, the raw
 *      sso-link mint — operator-interview-link, design D1).
 *   4. Map an `EntryLinkRefused` refusal onto this endpoint's OWN literal
 *      response — request validation and every OTHER response body below
 *      are UNCHANGED from before the extraction, byte-identical
 *      (`SsoLinkMintTest.php`, `SsoLinkResponseGoldenTest.php`).
 *
 * Security invariants:
 * - Project is resolved SCOPED to the caller's org: cross-org → 404.
 * - Non-interviewable project → 422 `PROJECT_NOT_INTERVIEWABLE`, `candidate_ref`
 *   echoed byte-for-byte, before any mint decision is made (PR6).
 * - display_name absent/empty → 422 (NOT NULL in DB; prevents 500 at exchange).
 * - role_code for potential → 422 (surfaces integration bugs; do NOT silently null).
 * - Finished candidates (completato/errore) are rejected with 409.
 * - No Redis write at mint (jti consumed only at exchange).
 *
 * REQ: M2M SSO-Link Mint,
 *      M2M mint response is unchanged after the extraction
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */
final class SsoLinkController extends Controller
{
    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly ProjectInterviewability $projectInterviewability,
    ) {}

    /**
     * Mint an sso-link JWT.
     *
     * POST /api/m2m/sso-link
     * Auth: auth:api-m2m + ability:sso_link:generate
     *
     * Response (201): { "token": "<sso-link JWT>" }
     */
    public function store(Request $request): JsonResponse
    {
        /** @var ApiClient $client */
        $client = $request->user('api-m2m');
        $clientOrgId = $client->organization_id;

        // Validation call stays HERE, inline and verbatim: Scramble derives
        // this endpoint's requestBody schema (maxLength/required) from this
        // exact call site (openapi.json:3320-3358) — moving it into the
        // minter or a FormRequest would rewrite the exported /m2m/sso-link
        // node (design D1).
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'candidate_ref' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'role_code' => ['nullable', 'string', 'max:50'],
            'lang' => ['nullable', 'string', 'max:10'],
        ]);

        // Resolve project SCOPED to caller org (cross-org → 404) — the one
        // thing genuinely different between this caller and the operator
        // mint, so it stays here rather than inside the shared minter.
        $project = Project::where('organization_id', $clientOrgId)
            ->findOrFail((int) $validated['project_id']);

        // D5/D6 (framework-catalogue-authoring PR6) — before `mint`, same as
        // `EntryLinkController`/`ParticipantController`: the calling system
        // learns this before any candidate is ever invited.
        // `evaluateForCandidate()`, not a bare `evaluate()` (Z10, REQUIRED
        // BEFORE ARCHIVE) — it exempts a candidate who already has an
        // `InterviewSession` on this project, the same exemption the SSO
        // exchange and `/start` already apply (Z9); internally it still
        // falls back to ONE `evaluate()` call for a non-exempt candidate, so
        // this stays a single query for one refusal either way. Without the
        // exemption, re-minting a replacement sso-link for a mid-interview
        // candidate whose token expired was refused by an UNRELATED,
        // not-yet-reached competency's later misconfiguration.
        // `candidate_ref` echoed byte-for-byte, same reasoning as the M2M
        // participant refusal.
        $interviewability = $this->projectInterviewability->evaluateForCandidate($project, $validated['candidate_ref']);
        if (! $interviewability['interviewable']) {
            return response()->json([
                'error' => 'PROJECT_NOT_INTERVIEWABLE',
                'competency_codes' => $interviewability['unsatisfied_competency_codes'],
                'candidate_ref' => $validated['candidate_ref'],
            ], 422);
        }

        try {
            $minted = $this->minter->mint(
                $project,
                $validated['candidate_ref'],
                $validated['display_name'],
                $validated['email'],
                $validated['role_code'] ?? null,
                $validated['lang'] ?? null,
            );
        } catch (EntryLinkRefused $e) {
            return match ($e->reason) {
                // (participant-error-recovery D3) Completed vs Failed: both stay
                // 409, only the message + machine-facing `reason` differ. An
                // `errore` participant is recoverable by an operator — reporting
                // it as "completed" would be false.
                EntryLinkRefusalReason::Completed => response()->json(
                    [
                        'message' => 'Conflict: participant has already completed this assessment.',
                        'reason' => 'completed',
                    ],
                    409
                ),
                EntryLinkRefusalReason::Failed => response()->json(
                    [
                        'message' => "Conflict: this participant's assessment failed and must be re-opened by an operator before a new link can be issued.",
                        'reason' => 'failed',
                    ],
                    409
                ),
                EntryLinkRefusalReason::RoleCode => response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['role_code' => [$e->getMessage()]],
                ], 422),
                EntryLinkRefusalReason::Gates => response()->json(
                    ['message' => 'Access denied.'],
                    403
                ),
            };
        }

        return response()->json(['token' => $minted->token], 201);
    }
}
