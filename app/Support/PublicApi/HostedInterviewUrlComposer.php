<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Support\Sso\EntryLinkUrlComposer;
use RuntimeException;

/**
 * Composes the absolute BEAI Public API (`/v1`) hosted interview URL —
 * `https://interview.beai.example/i/{token}` (SPEC.md §3.5 "Hosted URL"),
 * public-api step 5, G-33: "Step 5 ships `/i/{token}` (top-level, same
 * chrome as the SSO link flow, no framing)."
 *
 * Origin: `config('public_api.interview_url')`, falling back to
 * `config('interview.candidate_app_url')` — the SAME origin the SSO link
 * flow's `/interview/{token}` page already runs on, since step 5 reuses
 * that page's chrome rather than standing up a separate host. Locale
 * prefixing reuses `App\Support\Sso\EntryLinkUrlComposer::resolvePrefix()`
 * verbatim (made `public` for exactly this reuse) — "which locales get a
 * prefix" must answer identically on both pages; only the origin and the
 * path SEGMENT (`i` here, `interview` there) differ.
 */
final class HostedInterviewUrlComposer
{
    public function __construct(
        private readonly EntryLinkUrlComposer $entryLinkUrlComposer = new EntryLinkUrlComposer,
    ) {}

    /**
     * @throws RuntimeException when neither `INTERVIEW_URL` nor
     *                          `CANDIDATE_APP_URL` is configured — fails loud, same discipline
     *                          `EntryLinkUrlComposer::resolveOrigin()` applies for its own origin.
     */
    public function compose(string $token, ?string $lang): string
    {
        $configured = config('public_api.interview_url') ?: config('interview.candidate_app_url');

        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException(
                'HostedInterviewUrlComposer: neither INTERVIEW_URL nor CANDIDATE_APP_URL is configured.'
            );
        }

        $origin = rtrim($configured, '/');
        $prefix = $this->entryLinkUrlComposer->resolvePrefix($lang);

        return $prefix === null
            ? "{$origin}/i/{$token}"
            : "{$origin}/{$prefix}/i/{$token}";
    }
}
