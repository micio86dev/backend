<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Scheduling\CreateScheduledParticipant;
use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Http\Controllers\Controller;
use App\Http\Resources\ParticipantEnrolmentResource;
use App\Jobs\SendCandidateInvitationJob;
use App\Models\Project;
use App\Policies\ParticipantPolicy;
use App\Rules\NotPlaceholderEmail;
use App\Rules\ScheduledStartWithinLeadTime;
use App\Support\Participant\ExternalReference;
use App\Support\Participant\PlaceholderEmail;
use App\Support\Project\ProjectInterviewability;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Sso\EntryLinkUrlComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
 *   2. Validate the request body (mirrors the M2M mint's body verbatim),
 *      including the optional `scheduled_at` (interview-scheduling, design
 *      AD-2/AD-3) validated via the shared `ScheduledStartWithinLeadTime`
 *      rule — BEFORE `send_email` is ever read, since a scheduled request
 *      implies no email decision is relevant.
 *   3. Resolve Project::findOrFail, scoped by TenantContext's TenantScoped
 *      global scope (cross-org → 404).
 *   4. `ProjectInterviewability::evaluateForCandidate()` (framework-catalogue-
 *      authoring PR6, D5/D6; Z10) — 422 `PROJECT_NOT_INTERVIEWABLE` +
 *      `competency_codes` before the minter is ever reached, unless this
 *      candidate already has a session.
 *   5. SCHEDULED BRANCH (interview-scheduling, design AD-1 amendment): when
 *      `scheduled_at` is present, delegate to `CreateScheduledParticipant`
 *      (the SAME shared action `M2m\ParticipantController::store()`'s own
 *      scheduled branch uses) and return 201 with a
 *      `ParticipantEnrolmentResource` (the candidate shape plus `external_id`
 *      and `source`) — no mint, no `entry_url`, no email. `EntryLinkMinter`/
 *      `SendCandidateInvitationJob` are never reached on this branch.
 *      When `scheduled_at` is absent, behavior below is byte-for-byte
 *      unchanged — this is a strict superset of steps 6-8.
 *   6. Delegate the mint decision to EntryLinkMinter::mint() — the SAME
 *      shared logic the M2M mint uses (design D1).
 *   7. Compose the absolute entry_url via EntryLinkUrlComposer — fails loud
 *      (500) if CANDIDATE_APP_URL is unconfigured.
 *   8. Respond 201 { entry_url, expires_at } — never the bare token (design
 *      D1's "operator-facing payload" rule).
 *
 * Optional external reference (candidate-external-reference): `external_id`
 * (integer, 1..2^53-1) and `source` (string, at most 180 characters), validated
 * by the shared `ExternalReference::rules()`. On the scheduled branch they are
 * written on the eagerly created row; on the immediate branch they travel in
 * the sso-link token as claims (only when present) and the exchange persists
 * them. Those claims are readable by whoever holds the link (a JWT payload is
 * base64, not encrypted), like `email` and `display_name`: do not put a secret
 * in `source`.
 *
 * REQ: Operator-Facing Entry Link Mint Endpoint,
 *      Entry Link Response Composes the Absolute URL,
 *      Optional Scheduled Start On Participant Creation,
 *      Scheduled Start Must Be In The Future
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md,
 *      sdd/interview-scheduling/spec)
 */
final class EntryLinkController extends Controller
{
    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly EntryLinkUrlComposer $composer,
        private readonly ProjectInterviewability $projectInterviewability,
        private readonly CreateScheduledParticipant $createScheduledParticipant,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ParticipantPolicy::MODEL);

        // Validation call stays HERE, inline and verbatim — same reasoning as
        // SsoLinkController::store (design D1): Scramble derives this
        // endpoint's requestBody schema from this exact call site.
        //
        // `scheduled_at` (interview-scheduling, design AD-2/AD-3): optional future
        // start time. The rule object owns the explicit-offset check, the future
        // check, and the minimum-lead-time check — the SAME rule object the M2M
        // create and the reschedule endpoint use, never re-typed as inline logic
        // three times.
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'candidate_ref' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', new NotPlaceholderEmail('candidate_ref')],
            'display_name' => ['required', 'string', 'max:255'],
            'role_code' => ['nullable', 'string', 'max:50'],
            'lang' => ['nullable', 'string', 'max:10'],
            // Optional start time of a scheduled interview: an ISO 8601 date-time with an explicit offset, in the future and at least the minimum lead time ahead.
            'scheduled_at' => ['sometimes', new ScheduledStartWithinLeadTime],
            // Defaults to TRUE. The operator pressed "invite a candidate";
            // producing a link and silently not sending it is the behaviour
            // that made this feature necessary in the first place. An operator
            // who wants to deliver the link some other way opts out explicitly.
            'send_email' => ['sometimes', 'boolean'],
            // Spread INTO the inline call, never hoisted out of it: Scramble
            // evaluates this array to derive the requestBody, and the shared
            // rules keep every surface accepting exactly the same values.
            ...ExternalReference::rules(),
        ]);

        // Project is resolved manually (not route model binding), scoped by
        // TenantContext's TenantScoped global scope — cross-org → 404
        // (mirrors ProjectController's documented reason).
        $project = Project::findOrFail((int) $validated['project_id']);

        // D5/D6 (framework-catalogue-authoring PR6) — evaluated at MINT
        // time, and re-evaluated again at USE time by the SSO exchange and
        // `/start` (a token minted while interviewable is not a standing
        // exemption). `evaluate()`, never a bare
        // `unsatisfiedCompetencyCodes() !== []` check on its own — the
        // latter is vacuously `[]` for a project with ZERO selected
        // competencies, which is still not interviewable (D5); `evaluate()`
        // also avoids running the same query twice for one refusal. The
        // operator gets the actionable detail a candidate never would: which
        // competencies are the problem (empty when the project simply has
        // none selected at all).
        //
        // Z10 (REQUIRED BEFORE ARCHIVE): `evaluateForCandidate()`, not a bare
        // `evaluate()` — exempts a candidate who already has an
        // `InterviewSession` on this project, the SAME exemption the SSO
        // exchange and `/start` already apply (Z9). Without it, a
        // mid-interview candidate whose token expired could never be issued
        // a replacement link once an UNRELATED, not-yet-reached competency
        // lost its questions.
        $interviewability = $this->projectInterviewability->evaluateForCandidate($project, $validated['candidate_ref']);
        if (! $interviewability['interviewable']) {
            return response()->json([
                'error' => 'PROJECT_NOT_INTERVIEWABLE',
                'competency_codes' => $interviewability['unsatisfied_competency_codes'],
            ], 422);
        }

        $externalReference = ExternalReference::fromValidated($validated);

        // interview-scheduling (design AD-1 amendment, tasks T-B1): the
        // SCHEDULED branch. Creates the participant row EAGERLY — this is
        // the only creation path this endpoint has ever had a reason to
        // write directly, since scheduling defers the very click the
        // immediate path still waits for (unchanged below). No mint, no
        // entry_url, no email — the sweep (PR-D) mints and sends later.
        if (array_key_exists('scheduled_at', $validated)) {
            $result = $this->createScheduledParticipant->handle(
                $project,
                $validated['candidate_ref'],
                $validated['display_name'],
                $validated['email'],
                $validated['role_code'] ?? null,
                $validated['lang'] ?? null,
                // ->utc() is load-bearing, not cosmetic: Eloquent's datetime
                // cast formats the Carbon instance in ITS OWN timezone when
                // writing to the DB (HasAttributes::fromDateTime() never
                // normalizes to app timezone), so a Carbon still holding a
                // non-zero offset (e.g. "+02:00") would persist its LOCAL
                // wall-clock digits as if they were already UTC — silently
                // shifting the stored instant by the offset. Every existing
                // test in EntryLinkScheduledCreationTest built its input from
                // a zero-offset UTC clock, so this bug was invisible until a
                // non-zero-offset round-trip case was added.
                Carbon::parse($validated['scheduled_at'])->utc(),
                $externalReference,
            );

            // A duplicate enrolment answers 409. `message` carries the CODE, not a
            // sentence: the response body is machine-facing (CLAUDE.md "machine-facing
            // responses are not localized"), and the backoffice already translates
            // codes through `translateServerCode`, as it does for
            // EntryLinkRefusalReason::Completed/Failed further down.
            if ($result['conflict'] !== null) {
                // The candidate is already enrolled in this project. `message` is `entry_link_participant_duplicate_email` or `entry_link_participant_duplicate_candidate_ref`, and `reason` names the duplicated field.
                return response()->json([
                    'message' => $result['conflict'] === 'duplicate_email'
                        ? 'entry_link_participant_duplicate_email'
                        : 'entry_link_participant_duplicate_candidate_ref',
                    'reason' => $result['conflict'],
                ], 409);
            }

            return response()->json(new ParticipantEnrolmentResource($result['participant']), 201);
        }

        try {
            $minted = $this->minter->mint(
                $project,
                $validated['candidate_ref'],
                $validated['display_name'],
                $validated['email'],
                $validated['role_code'] ?? null,
                $validated['lang'] ?? null,
                $externalReference,
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
        //
        // Never to a reusable-link visitor: its address is whatever the link
        // holder typed and was never verified, so BEAI does not write to it. And
        // never to a placeholder address (a legacy row, or a participant the
        // retention purge redacted): nobody can be written to there. The link is
        // still minted and returned, and `email_sent` stays truthful: the job is
        // not queued, so the answer is false. The job keeps its own placeholder
        // refusal as a second line of defence.
        $requestedEmail = (bool) ($validated['send_email'] ?? true);
        $addressIsPlaceholder = PlaceholderEmail::is($validated['email']);
        $emailSent = (bool) ($requestedEmail && ! $minted->targetsReusableLinkVisitor && ! $addressIsPlaceholder);

        if ($requestedEmail && ! $emailSent) {
            // One line, no context: neither the address nor the name belongs in
            // a log read by people who were never given them.
            Log::info($addressIsPlaceholder
                ? 'candidate invitation not queued: the participant holds a placeholder address, not an address a person gave.'
                : 'candidate invitation not queued: the participant is a reusable-link visitor with a self-declared address that was never verified.');
        }

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
                $project->organization?->primary_color,
                $project->organization?->absoluteLogoUrl(),
            );
        }

        return response()->json([
            'entry_url' => $entryUrl,
            'expires_at' => $minted->expiresAt->toISOString(),
            // Whether an invitation was queued, so the UI can say it was sent
            // rather than leaving the operator to guess. It is false when no mail
            // was asked for, and when none will go out: the participant is a
            // reusable-link visitor with a self-declared address, or holds a
            // placeholder address.
            'email_sent' => $emailSent,
        ], 201);
    }
}
