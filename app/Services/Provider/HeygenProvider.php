<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Enums\ProviderFailureClass;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderTranscriptShapeException;
use App\Models\InterviewSession;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\AvatarTemplates\TemplatePayload;
use App\Support\Provider\ProviderErrorMessage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HeyGen LiveAvatar provider implementation (C7a Phase 7.4).
 *
 * Endpoints (LiveAvatar v1):
 *   POST https://api.liveavatar.com/v1/contexts         — create avatar context
 *   POST https://api.liveavatar.com/v1/sessions/token   — issue session access token
 *   GET  https://api.liveavatar.com/v1/sessions/{ref}/transcript — fetch transcript
 *   DELETE https://api.liveavatar.com/v1/sessions/{ref} — teardown session
 *
 * Open question (C7a design): LiveAvatar v1 vs native HeyGen REST endpoint.
 * C7a tests use Http::fake against these paths. Confirm with client before live deploy.
 *
 * Security:
 * - API key lives ONLY in config('interview.heygen.api_key') (env HEYGEN_API_KEY).
 * - Raw provider HTTP response bodies (which may echo key material) are ALWAYS REDACTED
 *   before re-throwing as ProviderException. The key MUST NEVER reach exception messages,
 *   log channels, or Sentry (task 14.3).
 *
 * REQ: HeygenProvider — provider session management (C7a)
 */
class HeygenProvider implements ProviderSessionService
{
    private const BASE_URL = 'https://api.liveavatar.com/v1';

    /**
     * Demo-proven `POST /sessions/token` template fields (PR2 D2, delta spec
     * "Avatar Identity Belongs to the Session-Token Call").
     *
     * `TemplatePayload::heygen()` also emits `max_session_duration`,
     * `video_settings.encoding`, and `voice_settings.*` — those came from
     * `avatar-tester`, a testbed, and have NO demonstrated-working call behind
     * them. Sending them risks a second 422 on every /start; they stay OFF by
     * default and are widened only via `interview.heygen.extra_token_fields`
     * (a config change, never a deploy) once smoke-verified.
     *
     * @wire-source legacy-demo/src/pages/api/interview/start.ts:206-221
     */
    private const TOKEN_FIELD_ALLOWLIST = [
        'avatar_id',
        'avatar_persona.voice_id',
        // 'avatar_persona.language' is deliberately ABSENT: the avatar's spoken
        // language follows the project and a template may not override it (D1).
        // This line is a statement of intent, not the mechanism — `TemplatePayload`
        // no longer emits the key at all, and this allowlist is union'd with
        // `interview.heygen.extra_token_fields`, so on its own it could be
        // re-opened by an env change with no deploy.
        'interactivity_type',
        'video_settings.quality',
    ];

    /**
     * Issue a new HeyGen LiveAvatar session token.
     *
     * Steps (two calls, DIFFERENT bodies — @wire-source start.ts:246-267 + :206-221):
     *   1. POST /contexts       → {name, prompt, opening_text} → reads data.id
     *   2. POST /sessions/token → avatar identity + data.id → session_token + session_id
     *
     * Called OUTSIDE any DB transaction.
     *
     * @throws ProviderException on HTTP 4xx/5xx; raw body redacted (task 14.3).
     */
    public function issue(InterviewSession $session, QuestionContext $ctx): ProviderToken
    {
        $apiKey = (string) config('interview.heygen.api_key', '');

        $ctxResponse = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->post(self::BASE_URL.'/contexts', $this->buildContextBody($session, $ctx));

        if (! $ctxResponse->successful()) {
            $this->throwRedacted($apiKey, $ctxResponse, 'context creation failed');
        }

        // @wire-source legacy-demo/src/pages/api/interview/start.ts:265 — the id is
        // read from `data.id`. `data.context_id` does NOT exist in the real contract.
        $contextId = (string) $ctxResponse->json('data.id', '');

        $tokenResponse = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->post(self::BASE_URL.'/sessions/token', $this->buildSessionTokenBody($ctx, $contextId));

        if (! $tokenResponse->successful()) {
            $this->throwRedacted($apiKey, $tokenResponse, 'session token issuance failed');
        }

        $data = $tokenResponse->json('data', []);

        // @wire-source legacy-demo/src/pages/api/interview/start.ts:222-227 — the
        // real field is `data.session_token`. `data.access_token` does NOT exist
        // in the real contract (hotfix 0.22.1: this was the second latent bug —
        // it would have fired immediately after the missing-avatar_id 422 was fixed).
        $sessionToken = $data['session_token'] ?? null;

        // @wire-source start.ts:227 (`data.session_id ?? null`) — session_id is
        // NULLABLE in the real contract. `provider_session_ref` is already nullable
        // end-to-end (DB column, ProviderToken, reconcileTranscript()'s `$ref === null`
        // guard, and the RESUME teardown path's `$oldRef` null-check) — a null
        // session_id degrades exactly like an already-supported "no ref" session:
        // transcript reconciliation returns [] (best-effort) and a later resume's
        // teardown is skipped. Requiring it here would turn a benign, already-handled
        // case into a fatal malformed-response error it is not.
        $sessionId = $data['session_id'] ?? null;

        if ($sessionToken === null) {
            throw new ProviderException(
                'HeyGen: malformed token response (missing session_token)',
                ProviderFailureClass::Upstream,
            );
        }

        return new ProviderToken(
            provider: 'heygen',
            token: $sessionToken,
            conversation_url: null,
            provider_session_ref: $sessionId,
        );
    }

    /**
     * Build the `POST /v1/contexts` body — exactly `{name, prompt, opening_text}`.
     *
     * `opening_text` (PR3, design D9/D11): sourced from `QuestionContext.openingText`,
     * composed by `App\Services\Conversation\OpeningTextComposer`. Omitted (not null)
     * when the caller passes no opening text — same "unset means absent" convention
     * `prompt` already uses.
     *
     * @wire-source legacy-demo/src/pages/api/interview/start.ts:246-267
     *
     * @return array<string, string>
     */
    private function buildContextBody(InterviewSession $session, QuestionContext $ctx): array
    {
        $body = [
            // Unique per LiveAvatar account (@wire-source start.ts:250-253 — a stable
            // name collided on every interview after the first). F3: a RESUME re-issues
            // this call for the SAME interview_session_id (handleResumeInCorso ->
            // ProviderSessionService::issue()), so the id alone is not enough — the ULID
            // makes every issue() call distinct even within the same millisecond.
            // No candidate PII: interview_session_id is BEAI's own opaque internal id,
            // never candidate_ref (ratified decision #8).
            'name' => sprintf('beai-%d-%s', $session->id, (string) Str::ulid()),
        ];

        // @wire-source start.ts:255 — the real field name is `prompt`, NOT
        // `system_prompt` (which was never a real LiveAvatar field). Omitted (not
        // null) when there is no composed prompt yet, matching TemplatePayload's
        // "unset means absent" convention.
        if ($ctx->systemPrompt !== null) {
            $body['prompt'] = $ctx->systemPrompt;
        }

        // @wire-source start.ts:255 (`opening_text`, the avatar's first spoken line,
        // composed SEPARATELY from `prompt` — PR3, design D9). Omitted when the caller
        // has no composed greeting (e.g. the RESUME-degraded path never fails to
        // compose an opening — only the system prompt can degrade — but the null
        // path is kept for symmetry and defensive callers).
        if ($ctx->openingText !== null) {
            $body['opening_text'] = $ctx->openingText;
        }

        return $body;
    }

    /**
     * Build the `POST /v1/sessions/token` body — avatar identity lives HERE, never
     * on `/contexts` (PR2 D1/D2, delta spec "Avatar Identity Belongs to the
     * Session-Token Call").
     *
     * Hotfix 0.22.1 — root cause of the production 422 ("avatar_id: Field
     * required"): avatar identity previously came ONLY from the org's active
     * `AvatarTemplate`. no organization is required to have one. Pinned by HeygenProviderTest's platform-default cases: they fail loudly if the fallback stops supplying an identity., so `$templateFields` was `[]` and
     * `avatar_id` was never sent. Three-layer precedence, weakest to strongest:
     *   1. `$platformDefault` — this method's own floor. Always supplies
     *      avatar_id/voice_id/language (from `interview.heygen.*` config /
     *      `$ctx->language`) and the two proven-constant fields
     *      `interactivity_type`/`video_settings.quality` (@wire-source start.ts:
     *      216-220 — hardcoded "CONVERSATIONAL"/"low" in the demonstrated-working
     *      call, NOT template-sourced there).
     *   2. `$templateFields` — an org's active `AvatarTemplate`, when it sets a
     *      value. Overrides the platform default on a per-key basis: a template
     *      that customizes `videoQuality` still wins over the "low" floor. C14
     *      avatar-template customization is preserved, not regressed by this fix.
     *   3. `$providerOwned` — `mode`/`is_sandbox`/`avatar_persona.context_id`.
     *      Technical protocol constants; never template-overridable.
     *
     * `array_replace_recursive` (not `array_merge`) across all three: the template
     * emits `avatar_persona.{voice_id, language}` and the platform default emits
     * the same two keys plus `avatar_id`; a shallow merge would replace the whole
     * `avatar_persona` node and silently drop voice/language — the C14
     * "operator sets a voice, hears no difference" failure at a new address.
     *
     * @wire-source legacy-demo/src/pages/api/interview/start.ts:206-221
     *
     * @return array<string, mixed>
     */
    private function buildSessionTokenBody(QuestionContext $ctx, string $contextId): array
    {
        $platformDefault = $this->platformDefaultTokenFields($ctx);
        $templateFields = $this->allowlistedTemplateFields($this->activeTemplateConfig());

        $providerOwned = [
            'mode' => 'FULL',
            'is_sandbox' => false,
            'avatar_persona' => [
                'context_id' => $contextId,
            ],
        ];

        return array_replace_recursive($platformDefault, $templateFields, $providerOwned);
    }

    /**
     * The platform-default `/sessions/token` fields (hotfix 0.22.1) — the floor
     * every request gets when the org has no active `AvatarTemplate`, or when its
     * template leaves a field unset.
     *
     * `avatar_id`/`avatar_persona.voice_id` come from `interview.heygen.{avatar_id,
     * voice_id}` config (env `HEYGEN_AVATAR_ID`/`HEYGEN_VOICE_ID`, `LIVEAVATAR_*`
     * alias) — omitted (not sent as `""`) when unset, matching TemplatePayload's
     * "unset means absent" convention.
     *
     * `avatar_persona.language` — BEAI is multi-tenant/multilingual (CLAUDE.md i18n
     * mandate); the avatar must speak the PROJECT's language, not a fixed env
     * value like the single-tenant demo used. Sourced from `$ctx->language`
     * (threaded by the controller from `$project->language`, the same locale PR3/
     * D9 already uses for the opening greeting), falling back to
     * `interview.heygen.language` only when the caller passes no language
     * (e.g. `ProviderSmokeCheck`'s standalone, unsaved fake session).
     *
     * `interactivity_type`/`video_settings.quality` — proven-constant values from
     * the demonstrated-working call (@wire-source start.ts:216-220), NOT
     * config-driven: always present unless an org's template explicitly overrides
     * them via the existing `TOKEN_FIELD_ALLOWLIST` mechanism (merged in AFTER
     * this method's return value, see `buildSessionTokenBody()`).
     *
     * @wire-source legacy-demo/src/pages/api/interview/start.ts:206-221
     *
     * @return array<string, mixed>
     */
    private function platformDefaultTokenFields(QuestionContext $ctx): array
    {
        $body = [
            'interactivity_type' => 'CONVERSATIONAL',
            'video_settings' => [
                'quality' => 'low',
            ],
        ];

        $avatarId = config('interview.heygen.avatar_id');

        if (is_string($avatarId) && $avatarId !== '') {
            $body['avatar_id'] = $avatarId;
        }

        $voiceId = config('interview.heygen.voice_id');

        if (is_string($voiceId) && $voiceId !== '') {
            $body['avatar_persona']['voice_id'] = $voiceId;
        }

        $language = $ctx->language ?? config('interview.heygen.language');

        if (is_string($language) && $language !== '') {
            $body['avatar_persona']['language'] = $language;
        }

        return $body;
    }

    /**
     * Filter `TemplatePayload::heygen()`'s output down to the demo-proven field set
     * (PR2 D2). Everything outside `TOKEN_FIELD_ALLOWLIST` — union'd with
     * `config('interview.heygen.extra_token_fields', [])` — is dropped, not sent.
     *
     * @param  array<string, mixed>  $templateConfig
     * @return array<string, mixed>
     */
    private function allowlistedTemplateFields(array $templateConfig): array
    {
        $mapped = TemplatePayload::heygen($templateConfig);

        $allowlist = array_unique(array_merge(
            self::TOKEN_FIELD_ALLOWLIST,
            (array) config('interview.heygen.extra_token_fields', []),
        ));

        $filtered = [];

        foreach ($allowlist as $path) {
            $value = data_get($mapped, $path);

            if ($value !== null) {
                data_set($filtered, $path, $value);
            }
        }

        return $filtered;
    }

    /**
     * Fetch the authoritative HeyGen server-side transcript.
     *
     * Returns an array of utterance-like rows for REPLACE reconciliation at /end.
     * Each row: ['speaker' => 'candidate'|'avatar', 'text' => string, 'ts' => ISO8601 string].
     *
     * Fail-loud on shape drift (PR4, design D7): HTTP non-2xx stays soft (unchanged
     * — log + return `[]`, best-effort transcript). But a 2xx whose body does NOT
     * match the real contract (`data.transcript_data`, rows keyed `role`/`transcript`)
     * THROWS `ProviderTranscriptShapeException` instead of silently degrading to `[]`
     * — see `InterviewController::replaceUtterances()` (F1): a silently-empty
     * transcript here used to DELETE every persisted utterance and insert nothing.
     *
     * @wire-source legacy-demo/src/pages/api/interview/end.ts:76-84
     *
     * @return array<int, array{speaker: string, text: string, ts: string}>
     *
     * @throws ProviderTranscriptShapeException When the 2xx body does not match the real shape.
     */
    public function reconcileTranscript(InterviewSession $session): array
    {
        $apiKey = (string) config('interview.heygen.api_key', '');
        $ref = $session->provider_session_ref;

        if ($ref === null) {
            return [];
        }

        $response = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->get(self::BASE_URL.'/sessions/'.$ref.'/transcript');

        if (! $response->successful()) {
            // Non-fatal: log and return empty — best effort transcript
            Log::warning('HeyGen: transcript fetch failed', [
                'session_id' => $session->id,
                'status' => $response->status(),
                // Raw body is NOT logged (may contain key material)
            ]);

            return [];
        }

        // @wire-source end.ts:76-84 — the real field is `data.transcript_data`,
        // NOT `data` directly. Absent/not-array = a genuine contract drift, not a
        // valid "no transcript yet" empty state (that's `[]` present UNDER
        // transcript_data — checked below by array_map simply having nothing to do).
        $rows = $response->json('data.transcript_data');

        if (! is_array($rows)) {
            throw new ProviderTranscriptShapeException(
                "HeyGen: transcript response missing or malformed 'data.transcript_data' (session {$session->id})",
            );
        }

        return array_map(function (mixed $row) use ($session): array {
            if (! is_array($row) || ! isset($row['role'], $row['transcript']) || ! is_string($row['transcript'])) {
                throw new ProviderTranscriptShapeException(
                    "HeyGen: transcript row missing 'role' or 'transcript' (session {$session->id})",
                );
            }

            // @wire-source none — inferred: the demo never reads a role beyond
            // user/assistant; candidate/avatar/agent are defensive synonyms.
            // Any OTHER role is a genuine contract drift — today's code silently
            // misattributed every unknown role to the avatar; that is now a throw.
            $speaker = match ($row['role']) {
                'user', 'candidate' => 'candidate',
                'assistant', 'avatar', 'agent' => 'avatar',
                default => throw new ProviderTranscriptShapeException(
                    "HeyGen: unrecognized transcript role [{$row['role']}] (session {$session->id})",
                ),
            };

            return [
                'speaker' => $speaker,
                'text' => $row['transcript'],
                // time_ms absent → tolerate, fall back to now() (@wire-source none — inferred).
                'ts' => isset($row['time_ms'])
                    ? now()->subMilliseconds((int) $row['time_ms'])->toIso8601String()
                    : now()->toIso8601String(),
            ];
        }, $rows);
    }

    /**
     * Teardown (release) a HeyGen session.
     *
     * Best-effort — failure is logged but non-fatal.
     * ALWAYS takes a typed ProviderToken (no raw-string overload) per WARNING-6.
     */
    public function teardown(ProviderToken $token): void
    {
        if ($token->provider_session_ref === null) {
            return;
        }

        $apiKey = (string) config('interview.heygen.api_key', '');

        try {
            Http::withHeaders(['X-API-KEY' => $apiKey])
                ->delete(self::BASE_URL.'/sessions/'.$token->provider_session_ref);
        } catch (\Throwable $e) {
            // Best-effort — log without raw response (key material may be present)
            Log::warning('HeyGen: teardown failed', [
                'provider_session_ref' => $token->provider_session_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Throw a ProviderException, classified by HTTP status, with the raw response
     * body REDACTED — but the provider's own diagnostic message PRESERVED (PR1 D4, D6).
     *
     * Security (task 14.3): the raw provider response may echo the API key or contain
     * internal endpoint details. The key MUST be stripped before logging or re-throwing;
     * the full raw body is NEVER logged. `ProviderErrorMessage::extract()` pulls out only
     * the provider's own complaint (`message` ?? `error` ?? `data.message`), redacts the
     * key within it, and fails closed (returns null) if the key would otherwise survive.
     */
    private function throwRedacted(string $apiKey, Response $response, string $context): never
    {
        $status = $response->status();
        $class = self::classify($status);
        $extracted = ProviderErrorMessage::extract($response->json(), $apiKey);

        // Log at WARNING level with the extracted (already-redacted) message —
        // never the raw response body, which may still contain HEYGEN_API_KEY.
        Log::warning('HeyGen: provider call failed', [
            'context' => $context,
            'status' => $status,
            'class' => $class->value,
            'provider_message' => $extracted,
        ]);

        $suffix = $extracted !== null ? " — {$extracted}" : ' — provider response redacted';

        throw new ProviderException(
            "HeyGen: {$context} (HTTP {$status}){$suffix}",
            $class,
        );
    }

    /**
     * Classify an HTTP status into the three-way provider failure taxonomy (PR1 D4).
     */
    private static function classify(int $status): ProviderFailureClass
    {
        return match (true) {
            $status === 429 => ProviderFailureClass::Throttle,
            $status >= 500 => ProviderFailureClass::Upstream,
            default => ProviderFailureClass::ClientError,
        };
    }

    /**
     * The active template's config, or an empty array.
     *
     * Resolution failures are swallowed on purpose. An interview must not fail
     * because a cosmetic setting could not be read — the fallback is the
     * provider's own defaults, which is exactly what this product did before
     * templates existed.
     *
     * @return array<string, mixed>
     */
    private function activeTemplateConfig(): array
    {
        try {
            $template = app(ActiveTemplateResolver::class)->resolve();

            return $template === null ? [] : $template->config;
        } catch (\Throwable) {
            return [];
        }
    }
}
