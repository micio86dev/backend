<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Policies\ParticipantPolicy;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Sso\EntryLinkUrlComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EntryLinkController (operator-interview-link).
 *
 * Mints a candidate entry link for an authenticated backoffice operator.
 *
 * Route: POST /api/entry-links
 * Auth: auth:api + TenantContext
 *
 * Flow (design D2 — fixed order, 403 before 404):
 *   1. authorize('create', ParticipantPolicy::MODEL) — admin/operator only,
 *      runs BEFORE project resolution: minting is not a read, and a role
 *      check that needs no model cannot leak cross-org existence. The model
 *      class-string is read off the policy's own constant, not referenced
 *      directly here — see `ParticipantPolicy::MODEL`'s own docblock for the
 *      arch-guard reason.
 *   2. Validate the request body (mirrors the M2M mint's body verbatim).
 *   3. Resolve Project::findOrFail, scoped by TenantContext's TenantScoped
 *      global scope (cross-org → 404).
 *   4. Delegate the mint decision to EntryLinkMinter::mint() — the SAME
 *      shared logic the M2M mint uses (design D1).
 *   5. Compose the absolute entry_url via EntryLinkUrlComposer — fails loud
 *      (500) if CANDIDATE_APP_URL is unconfigured.
 *   6. Respond 201 { entry_url, expires_at } — never the bare token (design
 *      D1's "operator-facing payload" rule).
 *
 * REQ: Operator-Facing Entry Link Mint Endpoint,
 *      Entry Link Response Composes the Absolute URL
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */
final class EntryLinkController extends Controller
{
    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly EntryLinkUrlComposer $composer,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ParticipantPolicy::MODEL);

        // Validation call stays HERE, inline and verbatim — same reasoning as
        // SsoLinkController::store (design D1): Scramble derives this
        // endpoint's requestBody schema from this exact call site.
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'candidate_ref' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'role_code' => ['nullable', 'string', 'max:50'],
            'lang' => ['nullable', 'string', 'max:10'],
        ]);

        // Project is resolved manually (not route model binding), scoped by
        // TenantContext's TenantScoped global scope — cross-org → 404
        // (mirrors ProjectController's documented reason).
        $project = Project::findOrFail((int) $validated['project_id']);

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

        // Throws EntryLinkUrlNotConfigured (→ 500, bootstrap/app.php) when
        // CANDIDATE_APP_URL is unset — fails loud rather than returning a
        // 201 carrying a malformed link.
        $entryUrl = $this->composer->compose($minted->token, $minted->lang);

        return response()->json([
            'entry_url' => $entryUrl,
            'expires_at' => $minted->expiresAt->toISOString(),
        ], 201);
    }
}
