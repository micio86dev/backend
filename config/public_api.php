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
     *
     * Every default below is applied with `?:`, not `env()`'s own second
     * argument: Laravel's `env()` returns an empty string for a
     * present-but-empty `KEY=` line in `.env`, which never falls through to
     * that second-argument default — only a genuinely UNSET key does. `?:`
     * treats both "unset" and "empty" as "use the default".
     */
    'contract_path' => env('PUBLIC_API_CONTRACT_PATH') ?: base_path('public-api/openapi.yaml'),

    /*
     * Public base URL of `/v1` itself, e.g. `https://api.beai.example/v1`
     * (SPEC.md §2 architecture diagram, `openapi.yaml` `servers[0].url`).
     *
     * Defaults to this API's own origin (falling back to `http://localhost`
     * if `APP_URL` is itself unset or empty) plus the Laravel route prefix —
     * host-agnostic until `api.` is confirmed (decisions table Q6, G-03).
     */
    'base_url' => env('PUBLIC_API_URL') ?: rtrim((string) (env('APP_URL') ?: 'http://localhost'), '/').'/api/v1',

    /*
     * Base URL of the generated developer docs site (§6 "Developer area",
     * `developers.beai.example`). No default: unset (or blank) until
     * Q6/G-03 is resolved, and nothing in step 1 reads this for anything
     * other than documentation cross-links.
     */
    'developers_url' => env('DEVELOPERS_URL') ?: null,

    /*
     * Base URL of the hosted interview page (§3.5 "Hosted URL:
     * `https://interview.beai.example/i/{token}`"). No default HERE: this
     * key itself is null until `INTERVIEW_URL` is set explicitly — it never
     * falls back to another env var at THIS layer (not `APP_URL`).
     *
     * `App\Support\PublicApi\HostedInterviewUrlComposer` (public-api step 5,
     * gga finding 2) is the ONE place that DOES fall back further, to
     * `config('interview.candidate_app_url')` (`CANDIDATE_APP_URL`) — a
     * deliberate, documented exception to "never falls back to another env
     * var", not an oversight: step 5 ships the hosted page on the SAME host
     * and chrome as the existing SSO entry-link flow (G-33), so a local/dev
     * environment that has only ever configured `CANDIDATE_APP_URL` (the
     * entry-link origin, see `config/interview.php`) still gets a working
     * hosted URL without needing to set `INTERVIEW_URL` too. Once a real
     * `interview.` host is chosen (decisions table Q6, G-03) and
     * `INTERVIEW_URL` is set in every environment, that value always wins —
     * `?:` prefers it whenever it is present and non-empty.
     */
    'interview_url' => env('INTERVIEW_URL') ?: null,

    /*
     * Base URL of the `@beai/embed` CDN bundle (§4.1 packaging,
     * `https://cdn.beai.example/embed/v1.js`). No default for the same
     * reason as `developers_url` above.
     */
    'embed_cdn_url' => env('EMBED_CDN_URL') ?: null,

    /*
     * Public API (`/v1`) rate limits (public-api step 3 — SPEC.md §3.2
     * "Rate limiting: per organization, token bucket. Defaults `live` 600
     * req/min, `test` 120 req/min"). Requests per minute, per organization
     * per key mode. `App\Http\Middleware\PublicApi\RateLimitPublicApi`
     * reads these ONLY when the organization has no per-org override
     * (`organizations.public_api_rate_limit_live`/`_test` — both nullable,
     * configurable in the backoffice).
     *
     * G-30 (documented judgement call): the SPEC wording above says "token
     * bucket"; the actual implementation is a FIXED-WINDOW counter (see
     * `RateLimitPublicApi`'s own docblock) — a documented, accepted
     * deviation, not a bug. A fixed window can admit a short burst of up to
     * `2 * max` requests straddling a window boundary; it never admits more
     * than `max` WITHIN a single window.
     */
    'rate_limit' => [
        'live' => (int) (env('PUBLIC_API_RATE_LIMIT_LIVE') ?: 600),
        'test' => (int) (env('PUBLIC_API_RATE_LIMIT_TEST') ?: 120),
    ],

    /*
     * Public API (`/v1`) idempotency (public-api step 3 — SPEC.md §3.2
     * "Idempotency"). `App\Http\Middleware\PublicApi\IdempotencyKey` reads
     * these three. `lock_wait_seconds` is the ONE value tests override
     * (`config(['public_api.idempotency.lock_wait_seconds' => …])`) so a
     * concurrent-request test does not have to sleep the SPEC-mandated 5s
     * for real — the other two rarely need overriding at all.
     */
    'idempotency' => [
        'record_ttl_seconds' => (int) (env('PUBLIC_API_IDEMPOTENCY_RECORD_TTL_SECONDS') ?: 86400),
        'lock_ttl_seconds' => (int) (env('PUBLIC_API_IDEMPOTENCY_LOCK_TTL_SECONDS') ?: 30),
        'lock_wait_seconds' => (int) (env('PUBLIC_API_IDEMPOTENCY_LOCK_WAIT_SECONDS') ?: 5),
    ],

    /*
     * BEAI Public API (`/v1`) session tokens (public-api step 5, SPEC.md
     * §3.5 "Session tokens"). `App\Support\PublicApi\SessionTokenMinter`
     * reads both keys.
     *
     * `session_secret` is a DEDICATED secret — SPEC.md §3.5 "signed with a
     * dedicated secret (not the app key)" — never `config('jwt.secret')`
     * (that key signs the tymon-managed backoffice/candidate/M2M JWTs; a
     * session token is a SEPARATE, single-purpose, short-lived credential
     * with a narrower blast radius than every OTHER token this API mints,
     * and sharing a signing key would let a session-token compromise reach
     * those too). REQUIRED (fails loud via `SessionTokenMinter`, never
     * silently falls back to an empty string) in every environment except
     * `testing`, where `phpunit.xml` supplies a fixed test value so the
     * suite never depends on a real secret being configured.
     */
    'session_secret' => env('PUBLIC_API_SESSION_SECRET'),

    /*
     * Session token TTL in minutes (SPEC.md §3.5 "`exp = iat + 15 min`" —
     * "The only expiry in the API"). Empty/unset = 15.
     */
    'session_token_ttl_minutes' => (int) (env('PUBLIC_API_SESSION_TOKEN_TTL_MINUTES') ?: 15),

    /*
     * `App\PublicApi\Serializers\InterviewSerializer::hostedUrl()`'s
     * override (step 5 review follow-up, Part B item 4). `Interview.
     * hosted_url` is `null` on every real read for the reason that
     * serializer's own docblock gives — this key exists only so
     * `openapi.yaml`'s `string|null` declaration for that field
     * corresponds to a REACHABLE, non-dead code path rather than a bare
     * `return null;` no caller could ever branch on. No environment
     * (including production) sets it; leave it unset.
     */
    'interview_hosted_url_override' => env('PUBLIC_API_INTERVIEW_HOSTED_URL_OVERRIDE') ?: null,
];
