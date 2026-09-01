<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Http\Controllers\Controller;
use App\Jobs\SendCandidateInvitationJob;
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
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'role_code' => ['nullable', 'string', 'max:50'],
            'lang' => ['nullable', 'string', 'max:10'],
            // Defaults to TRUE. The operator pressed "invite a candidate";
            // producing a link and silently not sending it is the behaviour
            // that made this feature necessary in the first place. An operator
            // who wants to deliver the link some other way opts out explicitly.
            'send_email' => ['sometimes', 'boolean'],
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
                // `message` carries the CODE, not a sentence. A response body
                // is machine-facing (CLAUDE.md), the API has no idea what
                // language the reader speaks, and the backoffice already
                // translates codes through `translateServerCode`. `reason` is
                // kept as-is: it is the documented field integrators match on,
                // and changing a published contract to tidy a duplicate would
                // be the wrong trade.
                EntryLinkRefusalReason::Completed => response()->json(
                    [
                        'message' => 'entry_link_participant_completed',
                        'reason' => 'completed',
                    ],
                    409
                ),
                EntryLinkRefusalReason::Failed => response()->json(
                    [
                        'message' => 'entry_link_participant_failed',
                        'reason' => 'failed',
                    ],
                    409
                ),
                // The framework's own 422 wording, kept verbatim: this shape
                // is what every client already parses for `errors`, and the
                // per-field message inside it is the one that actually reaches
                // the operator.
                EntryLinkRefusalReason::RoleCode => response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['role_code' => [$e->getMessage()]],
                ], 422),
                EntryLinkRefusalReason::Gates => response()->json(
                    ['message' => 'entry_link_project_closed'],
                    403
                ),
            };
        }

        // Throws EntryLinkUrlNotConfigured (→ 500, bootstrap/app.php) when
        // CANDIDATE_APP_URL is unset — fails loud rather than returning a
        // 201 carrying a malformed link.
        $entryUrl = $this->composer->compose($minted->token, $minted->lang);

        // Queued, not sent inline: the link exists and is valid whether or not
        // a mail provider is having a good minute, and failing the request
        // would leave the operator believing nothing happened when the token
        // has already been minted and its jti already spent.
        $emailSent = (bool) ($validated['send_email'] ?? true);

        // The candidate's own language, formatted in it. A date rendered in
        // the operator's locale inside a message written in the candidate's is
        // the kind of seam that makes a product feel machine-assembled.
        //
        // `locale()` is a getter AND a setter on Carbon, so its return type is
        // `static|string` and chaining off it does not type-check. Set it as a
        // statement on a copy, then format — the copy also keeps this from
        // mutating the instance the response reads `toISOString()` from.
        $expiresAt = $minted->expiresAt->copy();
        $expiresAt->locale($minted->lang);
        $expiresLabel = $expiresAt->isoFormat('LLL');

        if ($emailSent) {
            SendCandidateInvitationJob::dispatch(
                $validated['email'],
                $entryUrl,
                $validated['display_name'],
                (string) $project->organization?->name,
                $project->name,
                // The candidate's own language, formatted in it. A date
                // rendered in the operator's locale inside a message written
                // in the candidate's is the kind of seam that makes a product
                // feel machine-assembled.
                $expiresLabel,
                $minted->lang,
            );
        }

        return response()->json([
            'entry_url' => $entryUrl,
            'expires_at' => $minted->expiresAt->toISOString(),
            // Reported back so the UI can say "sent to grace@example.test"
            // rather than leaving the operator to guess whether it went.
            'email_sent' => $emailSent,
        ], 201);
    }
}
