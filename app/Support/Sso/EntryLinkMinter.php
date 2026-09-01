<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use Illuminate\Support\Carbon;
use Tymon\JWTAuth\JWTAuth;

/**
 * EntryLinkMinter (operator-interview-link, design D1).
 *
 * The single source of truth for the mint decision — entry gates, `role_code`
 * inheritance/validation, terminal-status refusal, and the raw sso-link
 * mint — shared by both `POST /api/m2m/sso-link` and `POST /api/entry-links`.
 * Extracted verbatim from `SsoLinkController` (M2m); the request-validation
 * call and the HTTP response literals stay per-caller (design D1) — Scramble
 * derives each controller's schema from those literal call sites.
 *
 * Takes an already-resolved `Project`, never a project_id: the org source is
 * the one genuine difference between the two callers
 * (`ApiClient->organization_id` vs. `TenantContext`'s global scope), so
 * scoping stays the caller's job and this component can never become a
 * cross-tenant read surface.
 *
 * REQ: Shared Entry Link Minting Logic,
 *      A gate refusal reason is consistent across both mints
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */
final class EntryLinkMinter
{
    /**
     * @throws EntryLinkRefused When the project's entry gates, the role_code,
     *                          or a terminal-status participant refuses the mint.
     */
    public function mint(
        Project $project,
        string $candidateRef,
        string $displayName,
        string $email,
        ?string $roleCode,
        ?string $lang,
    ): MintedEntryLink {
        if (! $this->projectIsAccessible($project)) {
            throw new EntryLinkRefused(EntryLinkRefusalReason::Gates);
        }

        $roleCodeError = $this->validateRoleCode($project, $roleCode);
        if ($roleCodeError !== null) {
            throw new EntryLinkRefused(EntryLinkRefusalReason::RoleCode, $roleCodeError);
        }

        $existingStatus = Participant::where('project_id', $project->id)
            ->where('candidate_ref', $candidateRef)
            ->value('status');

        // (participant-error-recovery D3) split by which terminal status:
        // 'completato' really is done; 'errore' is now recoverable by an
        // operator, so it must not be reported as completed.
        if ($existingStatus === 'completato') {
            throw new EntryLinkRefused(EntryLinkRefusalReason::Completed);
        }

        if ($existingStatus === 'errore') {
            throw new EntryLinkRefused(EntryLinkRefusalReason::Failed);
        }

        $resolvedLang = $lang ?? $project->language ?? config('app.fallback_locale', 'en');

        // An omitted role_code is INHERITED from the project, for standard
        // projects only — see SsoLinkController's original comment
        // (:96-117 pre-extraction) for why: a mint that returned 201 with a
        // null role_code the exchange would refuse also spent the jti,
        // because the exchange consumes it BEFORE evaluating its gates.
        $resolvedRoleCode = $roleCode;
        if ($resolvedRoleCode === null && $project->assessment_type === 'standard') {
            $resolvedRoleCode = $project->role_code;
        }

        $token = CandidateTokenFactory::mintSsoLink([
            'candidate_ref' => $candidateRef,
            'display_name' => $displayName,
            // Carried in the token because the participant row is not written
            // here — the EXCHANGE writes it, and it can only write what the
            // token tells it. A required column whose value is left behind at
            // mint time is a required column the exchange cannot satisfy.
            'email' => $email,
            'project_id' => $project->id,
            'org_id' => $project->organization_id,
            'role_code' => $resolvedRoleCode,
            'lang' => $resolvedLang,
        ]);

        // expires_at is read back from the token's OWN exp claim — never
        // independently recomputed — so it can never drift from what the
        // token actually carries.
        // The CONCRETE class, not the facade. `setToken()` lives on
        // `Tymon\JWTAuth\JWT`, but reaching it through the facade's static
        // proxy means static analysis has to boot the container to see it —
        // which it manages locally and not in CI, so this line was the last
        // thing keeping the pipeline red while passing on every developer
        // machine. The container aliases `tymon.jwt.auth` to this class, so
        // it is the same singleton, just reachable without a boot.
        $payload = app(JWTAuth::class)->setToken($token)->getPayload();
        $expiresAt = Carbon::createFromTimestamp((int) $payload->get('exp'));

        return new MintedEntryLink($token, $expiresAt, $resolvedLang);
    }

    /**
     * Check project entry gates (NULL-safe).
     *
     * status = 'active'
     * AND (goes_live_at IS NULL OR goes_live_at <= now())
     * AND (deadline_at  IS NULL OR deadline_at  >  now())
     */
    private function projectIsAccessible(Project $project): bool
    {
        if ($project->status !== 'active') {
            return false;
        }

        if ($project->goes_live_at !== null && $project->goes_live_at->isAfter(now())) {
            return false;
        }

        if ($project->deadline_at !== null && ! $project->deadline_at->isAfter(now())) {
            return false;
        }

        return true;
    }

    /**
     * Validate role_code against project type.
     *
     * @return string|null Error message if invalid, null if valid.
     */
    private function validateRoleCode(Project $project, ?string $roleCode): ?string
    {
        if ($project->assessment_type === 'potential') {
            if ($roleCode !== null) {
                return 'role_code must not be supplied for potential assessment projects.';
            }
        } elseif ($project->assessment_type === 'standard') {
            if ($roleCode !== null && $roleCode !== $project->role_code) {
                return 'role_code does not match the project role.';
            }
        }

        return null;
    }
}
