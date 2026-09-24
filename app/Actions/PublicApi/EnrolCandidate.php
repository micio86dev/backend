<?php

declare(strict_types=1);

namespace App\Actions\PublicApi;

use App\Enums\ApiKeyMode;
use App\Enums\AssessmentType;
use App\Exceptions\PublicApi\EnrolmentRefusalReason;
use App\Exceptions\PublicApi\EnrolmentRefused;
use App\Models\InterviewEvent;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\PublicApi\InterviewStatus;
use App\Support\PublicApi\SessionTokenMinter;
use App\Support\Sso\EntryLinkMinter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * `POST /v1/interviews` business logic (public-api step 5, SPEC.md §3.3
 * "Create interview — request"). Mirrors `App\Actions\Scheduling\
 * CreateScheduledParticipant`'s row-creation shape (`forceFill()` +
 * `DB::transaction()` + unique-constraint → conflict mapping) and
 * `App\Support\Sso\EntryLinkMinter`'s gate/inheritance logic, reused rather
 * than duplicated where the two overlap.
 *
 * Never sends an email or any calling-system notification — the calling
 * system owns invitations (task instruction; CLAUDE.md ruling 8 §"C12
 * notifications stay operator-facing"). `invited` is recorded as an EVENT
 * only, never a side effect that reaches outside this API.
 */
final class EnrolCandidate
{
    public function __construct(
        private readonly EntryLinkMinter $entryLinkMinter,
        private readonly SessionTokenMinter $sessionTokenMinter,
    ) {}

    /**
     * @param  array{candidate_ref: string, email: string, display_name: string, language?: string|null}  $candidate
     * @param  array<string, string>|null  $metadata
     *
     * @throws EnrolmentRefused
     */
    public function handle(
        Project $project,
        Organization $organization,
        array $candidate,
        ?array $metadata,
        ?string $exitRedirectUrl,
        ApiKeyMode $mode,
    ): EnrolmentResult {
        if (! $this->entryLinkMinter->projectIsAccessible($project)) {
            throw new EnrolmentRefused(EnrolmentRefusalReason::ProjectNotActive);
        }

        if ($exitRedirectUrl !== null && ! $this->hostIsAllowed($exitRedirectUrl, $organization)) {
            throw new EnrolmentRefused(EnrolmentRefusalReason::RedirectUrlNotAllowed);
        }

        // gga round 4 finding 7: the PUBLIC create path stores email
        // LOWER-CASED, and duplicate-checks case-insensitively — matching
        // `InterviewController::index()`'s own `lower(email) = ?` filter
        // (`Ana@x.com` then `ana@x.com` must collide, not silently create
        // two rows for what every mail system treats as ONE address). The
        // SSO ingress path (`SsoExchangeController`) is UNCHANGED — it
        // still stores the `email` claim exactly as the sso-link token
        // carries it, case included. That divergence, and the DB-level
        // unique index (`participants_project_id_email_unique`) still being
        // case-SENSITIVE (so a race between two differently-cased inserts
        // is not actually blocked at the constraint layer — only this
        // pre-check and the identical case-normalisation on write close
        // that window on the public path), is exactly why a functional
        // (lower(email)) unique index is a real follow-up, not yet done
        // here — tracked as G-43.
        $normalizedEmail = mb_strtolower($candidate['email']);

        // Uniqueness is per project, on EITHER axis (SPEC.md §3.3) — checked
        // up front so the caller gets the RIGHT reason (email vs
        // candidate_ref) rather than a generic constraint-violation mapping;
        // the unique DB indexes (`participants_project_id_email_unique`,
        // `participants_project_id_candidate_ref_unique`) remain the actual
        // race-safe guarantee — see the `QueryException` catch below.
        $existingByEmail = Participant::where('organization_id', $organization->id)
            ->where('project_id', $project->id)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->exists();

        if ($existingByEmail) {
            throw new EnrolmentRefused(EnrolmentRefusalReason::DuplicateEmail);
        }

        $existingByRef = Participant::where('organization_id', $organization->id)
            ->where('project_id', $project->id)
            ->where('candidate_ref', $candidate['candidate_ref'])
            ->exists();

        if ($existingByRef) {
            throw new EnrolmentRefused(EnrolmentRefusalReason::DuplicateCandidateRef);
        }

        // role_code inheritance (SPEC.md §3.3 "role_code is inherited from
        // the project") — the public request carries no role_code field at
        // all, so this is ALWAYS the project's own value, never a
        // caller-supplied override: null for `potential` (CLAUDE.md binding
        // constraint), the project's `role_code` for `standard`.
        $roleCode = $project->assessment_type === AssessmentType::Standard->value ? $project->role_code : null;

        $language = $candidate['language']
            ?? $project->language
            ?? config('app.fallback_locale', 'en');

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $organization->id, // server-side, NOT from request
            'project_id' => $project->id,
            'candidate_ref' => $candidate['candidate_ref'],
            'display_name' => $candidate['display_name'],
            'email' => $normalizedEmail,
            'role_code' => $roleCode,
            'language' => $language,
            'status' => InterviewStatus::Pending->toStored(),
            'metadata' => $metadata,
            'exit_redirect_url' => $exitRedirectUrl,
            'mode' => $mode,
        ]);

        // gga finding 3: minting the session token and stamping
        // `session_token_jti` both happen INSIDE this SAME transaction, not
        // after it commits. A mint failure (e.g. a missing/misconfigured
        // `public_api.session_secret` — `SessionTokenMinter::configuration()`
        // fails loud with a `RuntimeException`) now rolls the WHOLE
        // enrolment back — no participant row, no `created`/`invited`
        // events — instead of leaving a committed row with no usable
        // token: a caller who then retried the SAME request (or an
        // idempotency replay) would previously have hit `409
        // duplicate_enrolment` for an enrolment that never actually
        // finished, with no way to recover it.
        $sessionToken = null;

        try {
            DB::transaction(function () use ($participant, &$sessionToken): void {
                $participant->save();

                InterviewEvent::create([
                    'participant_id' => $participant->id,
                    'type' => 'created',
                    'occurred_at' => now(),
                ]);

                InterviewEvent::create([
                    'participant_id' => $participant->id,
                    'type' => 'invited',
                    'occurred_at' => now(),
                ]);

                $sessionToken = $this->sessionTokenMinter->mint($participant);

                $participant->forceFill(['session_token_jti' => $sessionToken->jti])->save();
            });
        } catch (QueryException $e) {
            $reason = match (true) {
                str_contains($e->getMessage(), 'participants_project_id_email_unique') => EnrolmentRefusalReason::DuplicateEmail,
                str_contains($e->getMessage(), 'participants_project_id_candidate_ref_unique') => EnrolmentRefusalReason::DuplicateCandidateRef,
                default => null,
            };

            if ($reason === null) {
                throw $e;
            }

            throw new EnrolmentRefused($reason);
        }

        // $sessionToken is ALWAYS set by the closure above once
        // DB::transaction() returns normally — a real, honest runtime guard
        // for the type checker (PHPStan cannot see into the closure's
        // control flow), never a masked failure path.
        if ($sessionToken === null) {
            throw new LogicException('EnrolCandidate: transaction committed without minting a session token.');
        }

        return new EnrolmentResult($participant, $sessionToken);
    }

    /**
     * Case-insensitive (step 5 review follow-up, item 7) — a hostname is
     * not case-sensitive (RFC 3986 §3.2.2), but the strict `in_array(...,
     * true)` comparison this replaced treated `HR.Acme.example` and
     * `hr.acme.example` as two different hosts, refusing a caller whose
     * URL merely capitalised a host `organizations.allowed_domains`
     * already names. Both sides are lower-cased with `mb_strtolower()` —
     * the SAME function `InterviewController::index()`'s own `?email=`
     * filter and `EnrolCandidate::handle()`'s own email normalisation
     * already use for the identical reason.
     */
    private function hostIsAllowed(string $url, Organization $organization): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $allowed = array_map(
            static fn (string $domain): string => mb_strtolower($domain),
            $organization->allowed_domains ?? [],
        );

        return in_array(mb_strtolower($host), $allowed, true);
    }
}
