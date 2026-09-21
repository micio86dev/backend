<?php

declare(strict_types=1);

/**
 * Scoring Engine configuration (C9 — Scoring Engine).
 *
 * Keys:
 *   validity_threshold        — minimum reliability [0..1] for a competency to be counted valid.
 *                               Client must ratify before IT go-live (open decision D7).
 *   model_version             — pinned LLM model identifier; change triggers a new prompt_version bump.
 *                               Used by AnthropicLLMProvider as the default model.
 *   prompt_version            — semantic version string for the scoring prompt; bump on any prompt edit.
 *   gate.count_unscorable_against_total — when true (default), unscorable competencies are included
 *                               in the gate denominator (counted against the 90% threshold).
 *                               Set to false to exclude unscorable from denominator (client-ratifiable).
 *   anthropic                 — Anthropic Messages API config (C9 D7 resolved — no SDK).
 *
 * REQ: Scoring config (C9 Phase 1 + D7 LLM binding)
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Validity Threshold
    |--------------------------------------------------------------------------
    |
    | Minimum reliability value [0..1] required for a competency to be treated
    | as "valid" in the 90% completion gate.
    |
    | Formula: reliability = assessed_count / total_indicators (R-A strategy).
    | Default 0.5 means at least 50% of indicators must have assessable evidence.
    | Client must ratify this value before IT production scoring go-live.
    |
    */
    'validity_threshold' => (float) env('SCORING_VALIDITY_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Model Version
    |--------------------------------------------------------------------------
    |
    | The pinned LLM model identifier recorded on every Evaluation row and
    | ai_requests row for traceability/determinism. Use the exact versioned ID,
    | never an alias (e.g. 'claude-haiku-4-5-20251001' not 'claude-haiku-4-5').
    |
    | Used by AnthropicLLMProvider as the default model when $options['model']
    | is not supplied by the caller.
    |
    */
    'model_version' => env('SCORING_MODEL_VERSION', 'claude-haiku-4-5-20251001'),

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | Which vendor is being billed. Stamped onto every ai_requests row, because
    | "estimated cost per provider and model" is a promise the observability
    | spec makes and an unattributable cost record cannot keep.
    |
    */
    'provider' => env('SCORING_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Cost Rates (USD per MILLION tokens)
    |--------------------------------------------------------------------------
    |
    | Keyed by the exact model id — never an alias, for the same reason
    | model_version is pinned exactly: an alias silently repoints and the cost
    | history stops meaning anything.
    |
    | These are ESTIMATES used to populate ai_requests.estimated_cost_usd at
    | write time. They are not an invoice and are not authoritative for billing.
    | An unknown model yields 0.0 rather than an exception: a missing rate must
    | never be the thing that stops a scoring job or loses the record of a call
    | that was actually made. A zero-cost row is visible in the dashboard as the
    | anomaly it is.
    |
    | Update deliberately when a vendor changes pricing; historical rows keep
    | the rate that applied when they were written, which is the whole reason
    | the value is stored rather than computed on read.
    |
    */
    'cost_rates_usd_per_million' => [
        'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00],
        'claude-sonnet-4-5-20250929' => ['input' => 3.00, 'output' => 15.00],
        'claude-opus-4-5-20251101' => ['input' => 5.00, 'output' => 25.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Version
    |--------------------------------------------------------------------------
    |
    | Semantic version string for the scoring prompt template. Bump this string
    | on ANY edit to the scoring prompt — enables per-Evaluation traceability.
    |
    | D8 parity guard (bars-full-scale-1-5): this default MUST always equal
    | .env.example's SCORING_PROMPT_VERSION — see the parity test in
    | tests/Unit/Services/PromptBuilderTest.php. Bumping only one of the two
    | leaves environments provisioned from .env.example stamping a stale
    | version on Evaluations. A deploy environment that sets
    | SCORING_PROMPT_VERSION explicitly (Railway production api service does)
    | overrides BOTH defaults and must be bumped separately, at deploy time —
    | this config/.env.example parity guard cannot see or enforce that value.
    |
    | Bumped 3.1.0 -> 3.2.0 (scoring-role-scoped-indicators): traceability only.
    | The prompt TEMPLATE is unchanged; WHICH indicators get injected into it
    | changed — the role-scoped BarsIndicatorLoader replaces the unscoped
    | competency-only query, so a competency's rubric no longer carries every
    | other role's anchors. An Evaluation scored before this fix is not
    | comparable to one scored after it, and prompt_version is the field that
    | says so.
    |
    */
    'prompt_version' => env('SCORING_PROMPT_VERSION', '3.2.0'),

    /*
    |--------------------------------------------------------------------------
    | Gate Policy
    |--------------------------------------------------------------------------
    |
    | count_unscorable_against_total:
    |   true  (default) — unscorable competencies (role_no_bars, anchor_translation_missing,
    |           llm_parse_error) are counted in the gate denominator and are NOT valid.
    |           They count AGAINST the 90% threshold. Safe/honest default.
    |   false — unscorable competencies are excluded from BOTH numerator and denominator.
    |           Flip to true to "ignore" unscorables in the gate. Client-ratifiable.
    |
    */
    'gate' => [
        'count_unscorable_against_total' => (bool) env('SCORING_GATE_COUNT_UNSCORABLE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic API Configuration (C9 D7 — resolved, no SDK)
    |--------------------------------------------------------------------------
    |
    | Production LLMProvider calls the Anthropic Messages API directly via
    | Laravel's Http client — NO third-party SDK, NO D25 dependency to pin.
    |
    | api_key:         ANTHROPIC_API_KEY env var — NEVER hardcode. Must be set
    |                  in production and the ai-integration CI environment.
    | base_url:        Anthropic API base URL (override for staging/proxy).
    | version:         anthropic-version header sent on every request.
    | max_tokens:      Default max_tokens for scoring responses.
    | timeout_seconds: Http client read timeout per request.
    |
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
        'timeout_seconds' => (int) env('ANTHROPIC_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Truncation-Only Retry (C13, scoring-failure-containment D8)
    |--------------------------------------------------------------------------
    |
    | A truncated LLM response (finish_reason = max_tokens) is retried exactly
    | ONCE, at an enlarged max_tokens budget, before the competency is
    | finalized as unscorable. Every field here is config-driven per the
    | ratified product answer; tests/Unit/Config/TruncationRetryConfigTest.php
    | pins the SHIPPED defaults so changing the cap requires editing a test —
    | deliberate and visible — while an operator retains the env override.
    |
    | enabled:           kill-switch. false makes ScoringFailureClassifier
    |                    return Terminal for ResponseTruncated unconditionally,
    |                    identical to Increment A's no-config-block behavior.
    | max_attempts:      how many retries are permitted (shipped: 1 — "exactly
    |                    ONE retry", per the ratified answer and the
    |                    Truncation-Only Retry requirement).
    | budget_multiplier: the retry's max_tokens = min(round(current * this), ceiling).
    | budget_ceiling:    hard cap on the retry's max_tokens, regardless of multiplier.
    |
    */
    'truncation_retry' => [
        'enabled' => (bool) env('SCORING_TRUNCATION_RETRY_ENABLED', true),
        'max_attempts' => (int) env('SCORING_TRUNCATION_RETRY_MAX_ATTEMPTS', 1),
        'budget_multiplier' => (float) env('SCORING_TRUNCATION_RETRY_MULTIPLIER', 2.0),
        'budget_ceiling' => (int) env('SCORING_TRUNCATION_RETRY_CEILING', 8192),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-hoc Audit (TypeSafe / Jev) — scoring-audit-jev, proposal AD-1, design D13
    |--------------------------------------------------------------------------
    |
    | `truncation_retry`-shaped, with tests/Unit/Config/AuditConfigTest.php
    | pinning the SHIPPED defaults the way TruncationRetryConfigTest.php does.
    |
    | enabled:          Kill switch (AD-1). In v1 "enabled" means an OPERATOR
    |                   MAY ASK — never that the platform spends. false makes
    |                   the trigger route refuse (409 audit_disabled) and an
    |                   already-dispatched job no-op with zero rows written.
    |                   No listener is registered on EvaluationCompleted, so
    |                   this flag never causes a run on its own.
    | api_key:          TYPESAFE_API_KEY env var. NEVER hardcode. Provisioned
    |                   in Railway `api` production only after the pending
    |                   GDPR sub-processor sign-off (CLAUDE.md ruling 2) names
    |                   this flow — see Phase 0.2 of the change's tasks.md.
    | base_url:         TypeSafe API base URL (override for staging/proxy).
    | judge_model:      The EXACT vendor model id, recorded verbatim on every
    |                   run as judge_model_version. UNVERIFIED (design.md
    |                   C-C) — this session had no network access to confirm
    |                   TypeSafe's published API against
    |                   https://docs.typesafe.ai/api.md /
    |                   https://docs.typesafe.ai/primitives/noul.md, so
    |                   'jev-1' is a placeholder pending that verification
    |                   task, following the pluggable-conversation-llm P5.0
    |                   precedent for an unresolved live-API question. An
    |                   alias would silently repoint and the judgment history
    |                   would stop meaning anything (mirrors model_version's
    |                   own reasoning above).
    | prompt_version:   Semver for the audit's three Noul questions (D2:
    |                   relevance/calibration/grounding). Bump on ANY edit to
    |                   them — the scoring prompt_version idiom, one grain over.
    | timeout_seconds:  Per-request Http timeout. Feeds
    |                   AuditEvaluationJob::$timeout's derivation (design.md
    |                   D7/C-F) — raising it materially changes that job's
    |                   own $timeout, which must stay strictly below
    |                   queue.runtime.worker_timeout.
    |
    | cost_rates_usd_per_million: OWN meter, keyed by the exact judge model
    |                   id. NEVER summed into the cost_rates_usd_per_million
    |                   above — AD-2: the two are different vendors on
    |                   different meters, and folding them would poison the
    |                   scoring-cost dashboard's zero-cost anomaly signal. An
    |                   unknown model yields estimated_cost_usd = NULL, never
    |                   0.0 (design.md D8) — a different fact from "free".
    |                   Empty until C-C's rate-card verification lands.
    |
    | No `support_threshold`, no `batch_size` key in v1 (design.md D9/D2):
    | v1 persists the raw probability with no derived band, and batching is a
    | fixed per-competency shape, not an operator-configurable value.
    |
    */
    'audit' => [
        'enabled' => (bool) env('SCORING_AUDIT_ENABLED', true),
        'api_key' => env('TYPESAFE_API_KEY', ''),
        'base_url' => env('TYPESAFE_BASE_URL', 'https://api.typesafe.ai'),
        'judge_model' => env('SCORING_AUDIT_JUDGE_MODEL', 'jev-1'),
        'prompt_version' => env('SCORING_AUDIT_PROMPT_VERSION', '1.0.0'),
        'timeout_seconds' => (int) env('SCORING_AUDIT_TIMEOUT', 30),
        'cost_rates_usd_per_million' => [
            // 'jev-1' => ['input' => ?, 'output' => ?],   // pending C-C
        ],
    ],

];
