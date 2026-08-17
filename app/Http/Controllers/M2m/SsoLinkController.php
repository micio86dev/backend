<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Project;
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
 *   2. Delegate the mint decision to `EntryLinkMinter::mint()` (entry gates,
 *      role_code inheritance/validation, terminal-status refusal, the raw
 *      sso-link mint — operator-interview-link, design D1).
 *   3. Map an `EntryLinkRefused` refusal onto this endpoint's OWN literal
 *      response — request validation and every response body below are
 *      UNCHANGED from before the extraction, byte-identical
 *      (`SsoLinkMintTest.php`, `SsoLinkResponseGoldenTest.php`).
 *
 * Security invariants:
 * - Project is resolved SCOPED to the caller's org: cross-org → 404.
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
            'display_name' => ['required', 'string', 'max:255'],
            'role_code' => ['nullable', 'string', 'max:50'],
            'lang' => ['nullable', 'string', 'max:10'],
        ]);

        // Resolve project SCOPED to caller org (cross-org → 404) — the one
        // thing genuinely different between this caller and the operator
        // mint, so it stays here rather than inside the shared minter.
        $project = Project::where('organization_id', $clientOrgId)
            ->findOrFail((int) $validated['project_id']);

        try {
            $minted = $this->minter->mint(
                $project,
                $validated['candidate_ref'],
                $validated['display_name'],
                $validated['role_code'] ?? null,
                $validated['lang'] ?? null,
            );
        } catch (EntryLinkRefused $e) {
            return match ($e->reason) {
                EntryLinkRefusalReason::Terminal => response()->json(
                    ['message' => 'Conflict: participant has already completed this assessment.'],
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
