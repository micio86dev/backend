<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) surface configuration.
 *
 * Step 1 of docs/specs/public-api/SPEC.md — §0 "Contract governance", §3.2
 * (conventions the contract documents) and §5.2 ("Health: `/v1/health`
 * ... for client monitors"). These four keys are the "four surfaces" the
 * spec's decisions table (Q6) names: the API host itself, the developer
 * docs, the hosted interview page, and the embed SDK's CDN — nothing in
 * this codebase hardcodes a hostname for any of them.
 */
return [
    /*
     * Absolute filesystem path to the vendored OpenAPI contract this API
     * implements (`public-api/openapi.yaml`).
     *
     * The wrapper's `docs/specs/public-api/openapi.yaml` stays the source of
     * truth; this vendored copy is what `Tests\Contract\ContractValidator`
     * loads at test time and what step 11's Scramble export is diffed
     * against (T-CONTRACT-001). Overridable so a future generated-copy path
     * (or a test double) does not require editing this file.
     */
    'contract_path' => env('PUBLIC_API_CONTRACT_PATH', base_path('public-api/openapi.yaml')),

    /*
     * Public base URL of `/v1` itself, e.g. `https://api.beai.example/v1`
     * (SPEC.md §2 architecture diagram, `openapi.yaml` `servers[0].url`).
     *
     * Defaults to this API's own origin plus the Laravel route prefix —
     * host-agnostic until `api.` is confirmed (decisions table Q6, G-03).
     */
    'base_url' => env('PUBLIC_API_URL', env('APP_URL').'/api/v1'),

    /*
     * Base URL of the generated developer docs site (§6 "Developer area",
     * `developers.beai.example`). No default: unset until Q6/G-03 is
     * resolved, and nothing in step 1 reads this for anything other than
     * documentation cross-links.
     */
    'developers_url' => env('DEVELOPERS_URL'),

    /*
     * Base URL of the hosted interview page (§3.5 "Hosted URL:
     * `https://interview.beai.example/i/{token}`").
     *
     * Falls back to `FRONTEND_URL` when that key is defined in this
     * deployment's environment; as of step 1 this codebase defines no such
     * key (only the unrelated `CANDIDATE_APP_URL`, entry-link origin for
     * the SSO ingress — a different surface, see config/interview.php), so
     * the effective default is `null` until the interview host is chosen.
     */
    'interview_url' => env('INTERVIEW_URL', env('FRONTEND_URL')),

    /*
     * Base URL of the `@beai/embed` CDN bundle (§4.1 packaging,
     * `https://cdn.beai.example/embed/v1.js`). No default for the same
     * reason as `developers_url` above.
     */
    'embed_cdn_url' => env('EMBED_CDN_URL'),
];
