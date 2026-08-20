<?php

declare(strict_types=1);

/**
 * Interview session configuration (C7a — Interview Session Mechanics).
 *
 * Keys:
 *   provider          — Default provider: 'heygen' | 'tavus'.
 *                       Overridable per-project via projects.provider_override.
 *   heygen.api_key    — HeyGen LiveAvatar API key (server-side only; NEVER returned to client).
 *   tavus.api_key     — Tavus API key (server-side only; NEVER returned to client).
 *   snapshot.max_encoded_bytes — Maximum allowed base64-ENCODED snapshot size (~2.7 MB).
 *                       At this limit the decoded payload is ~2 MB (base64 overhead ~33%).
 *                       Checked BEFORE decoding (reject early to avoid OOM on huge inputs).
 *
 * Security:
 * - Provider API keys are read from env and NEVER serialized into responses, logs, or jobs.
 * - HeygenProvider and TavusProvider MUST redact raw response bodies before re-throwing exceptions.
 *
 * Open question (C7a): HeyGen LiveAvatar vs native HeyGen REST endpoint to confirm.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Interview Provider
    |--------------------------------------------------------------------------
    |
    | The provider used when a project does not set provider_override.
    | Valid values: 'heygen' | 'tavus'
    |
    */
    'provider' => env('INTERVIEW_PROVIDER', 'heygen'),

    /*
    |--------------------------------------------------------------------------
    | HeyGen Provider Configuration
    |--------------------------------------------------------------------------
    */
    'heygen' => [
        'api_key' => env('HEYGEN_API_KEY'),

        /*
         * Dot-path allowlist widening for POST /sessions/token template fields
         * beyond HeygenProvider::TOKEN_FIELD_ALLOWLIST (PR2 D2, liveavatar-contract-
         * alignment). `voice_settings.*`, `video_settings.encoding`, and
         * `max_session_duration` came from `avatar-tester` (a testbed), NOT the
         * demo-proven call — they stay OFF until smoke-verified against the real
         * LiveAvatar API. Empty by default: sending an unproven field risks a 422
         * on every /start, the exact defect this change fixes.
         *
         * Example: ['voice_settings.speed', 'video_settings.encoding']
         */
        'extra_token_fields' => array_filter(explode(',', (string) env('HEYGEN_EXTRA_TOKEN_FIELDS', ''))),

        /*
         * PLATFORM-DEFAULT avatar identity (hotfix 0.22.1 — production outage:
         * HeyGen HTTP 422 "avatar_id: Field required" on EVERY /start, because
         * `HeygenProvider::buildSessionTokenBody()` sourced avatar_id/voice_id/
         * language ONLY from the org's active `AvatarTemplate`, and NO org has
         * one today).
         *
         * Reads the SAME env vars as `interview.demo.heygen.*` below (LIVEAVATAR_*
         * accepted as an alias of HEYGEN_*), but this key is DELIBERATELY a
         * separate config surface, never read via `interview.demo.*` from the
         * provider path: `demo.*` is `beai:demo-seed`-scoped (seeds one
         * organization's AvatarTemplate row) and could be repointed or removed
         * independently of what every tenant without a template needs at request
         * time. Precedence in `HegenProvider::buildSessionTokenBody()`: an org's
         * active template still wins when it sets a value; this is only the
         * fallback for the (today: universal) case where it does not.
         */
        'avatar_id' => env('HEYGEN_AVATAR_ID', env('LIVEAVATAR_AVATAR_ID')),
        'voice_id' => env('HEYGEN_VOICE_ID', env('LIVEAVATAR_VOICE_ID')),
        'language' => env('HEYGEN_LANGUAGE', env('LIVEAVATAR_LANGUAGE', 'it')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tavus Provider Configuration
    |--------------------------------------------------------------------------
    */
    'tavus' => [
        'api_key' => env('TAVUS_API_KEY'),

        /*
         * Concurrency self-heal (PR6, design D8). On a Tavus concurrency-limit
         * rejection, `TavusConcurrencyGuard` retries `_retries` times,
         * `_backoff_ms` apart, before the request degrades to a client-facing
         * 429 `provider_busy`. Ported from `legacy-demo` in HALF: the retry
         * only — the blind account-wide reap is deliberately NOT ported (see
         * `TavusConcurrencyGuard`'s class docblock). Settable to 0 to disable
         * retrying entirely (fails straight to 429 on the first rejection).
         */
        'concurrency_retries' => (int) env('TAVUS_CONCURRENCY_RETRIES', 3),
        'concurrency_backoff_ms' => (int) env('TAVUS_CONCURRENCY_BACKOFF_MS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshot Upload Limits
    |--------------------------------------------------------------------------
    |
    | max_encoded_bytes: Maximum base64-ENCODED string length to accept before
    | decoding. Checked FIRST (before decode) to prevent OOM on oversized inputs.
    |
    | ~2 764 800 bytes encoded ≈ ~2 MB decoded (base64 overhead is ~33%).
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Provider Cost Rates (C11 review surface)
    |--------------------------------------------------------------------------
    |
    | Used to ESTIMATE what a session cost. Neither provider exposes a
    | per-session billed amount through an API, so the figure is always an
    | estimate and must be labelled as one wherever it surfaces — an operator
    | who reads it as an invoice line will reconcile it against a real bill and
    | find a discrepancy that was never a defect.
    |
    | Defaults mirror `quint-avatar-tester/src/lib/pricing.ts`, verified against
    | THAT project's plan. BEAI's contracts may differ; these are env-overridable
    | precisely so a wrong rate is a config change, not a release.
    |
    | HeyGen bills credits per minute at a per-credit rate; Tavus bills per
    | conversational minute.
    |
    */
    'rates' => [
        'heygen' => [
            'credits_per_minute' => (float) env('HEYGEN_CREDITS_PER_MIN', 2),
            'usd_per_credit' => (float) env('HEYGEN_USD_PER_CREDIT', 0.10),
        ],
        'tavus' => [
            'usd_per_minute' => (float) env('TAVUS_USD_PER_MIN', 0.37),
        ],
    ],

    'snapshot' => [
        'max_encoded_bytes' => (int) env('SNAPSHOT_MAX_ENCODED_BYTES', 2_764_800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo Avatar Identity (beai:demo-seed)
    |--------------------------------------------------------------------------
    |
    | Resource identifiers `beai:demo-seed` writes into its demo
    | AvatarTemplate rows. LIVEAVATAR_* is accepted as an alias of HEYGEN_*:
    | LiveAvatar is HeyGen's product name for the same service. Falls back to
    | committed, WORKING values (never a `demo_*` placeholder) so a fresh
    | checkout with no env vars set still seeds a demo that connects.
    |
    */
    'demo' => [
        'heygen' => [
            'avatar_id' => env('HEYGEN_AVATAR_ID', env('LIVEAVATAR_AVATAR_ID', 'ab0765ad-69de-41fb-9f8a-bd01c3c52d6f')),
            'voice_id' => env('HEYGEN_VOICE_ID', env('LIVEAVATAR_VOICE_ID', 'c84af063-5ce2-4370-8ef8-dcd0ef903d43')),
            'language' => env('HEYGEN_LANGUAGE', env('LIVEAVATAR_LANGUAGE', 'it')),
        ],
        'tavus' => [
            'replica_id' => env('TAVUS_REPLICA_ID', 'rf4e9d9790f0'),
            'persona_id' => env('TAVUS_PERSONA_ID', 'p8a490c4dfd4'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Entry Link Origin (operator-interview-link)
    |--------------------------------------------------------------------------
    |
    | candidate_app_url — the origin of the CANDIDATE app (`frontend/`), NOT
    | the backoffice. `EntryLinkUrlComposer` composes the operator-facing
    | `entry_url` from this value and fails loud (`EntryLinkUrlNotConfigured`,
    | at compose time) when it is unset, empty, or not an absolute http(s)
    | URL — it deliberately has NO default and NEVER falls back to
    | `config('app.url')`, which is the API's OWN origin and would produce a
    | link that resolves against the wrong application.
    |
    | frontend_default_locale / frontend_locales mirror
    | `frontend/nuxt.config.ts`'s `defaultLocale: 'it'` and
    | `strategy: 'prefix_except_default'`: the default locale's URL carries no
    | prefix, every other listed locale is prefixed with its code, and a
    | resolved language outside this list falls back unprefixed (never a
    | 422 — see design.md D3).
    |
    */
    'candidate_app_url' => env('CANDIDATE_APP_URL'),

    'frontend_default_locale' => 'it',

    'frontend_locales' => ['it', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Provider Smoke Check Gate (PR4 — design D10, layer L3)
    |--------------------------------------------------------------------------
    |
    | `php artisan interview:smoke-check` refuses to run unless this is true.
    | Costs real provider credits/quota on every invocation — never set true in
    | CI or normal deploys; only export it manually in the shell that runs the
    | command deliberately.
    |
    */
    'smoke_enabled' => (bool) env('INTERVIEW_SMOKE_ENABLED', false),

];
