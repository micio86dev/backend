<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Enums\ProviderFailureClass;
use App\Exceptions\ProviderException;
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
        'avatar_persona.language',
        'interactivity_type',
        'video_settings.quality',
    ];

    /**
     * Issue a new HeyGen LiveAvatar session token.
     *
     * Steps (two calls, DIFFERENT bodies — @wire-source start.ts:246-267 + :206-221):
     *   1. POST /contexts       → {name, prompt} → reads data.id
     *   2. POST /sessions/token → avatar identity + data.id → access_token + session_id
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
            ->post(self::BASE_URL.'/sessions/token', $this->buildSessionTokenBody($contextId));

        if (! $tokenResponse->successful()) {
            $this->throwRedacted($apiKey, $tokenResponse, 'session token issuance failed');
        }

        $data = $tokenResponse->json('data', []);
        $accessToken = $data['access_token'] ?? null;
        $sessionId = $data['session_id'] ?? null;

        if ($accessToken === null || $sessionId === null) {
            throw new ProviderException(
                'HeyGen: malformed token response (missing access_token or session_id)',
                ProviderFailureClass::Upstream,
            );
        }

        return new ProviderToken(
            provider: 'heygen',
            token: $accessToken,
            conversation_url: null,
            provider_session_ref: $sessionId,
        );
    }

    /**
     * Build the `POST /v1/contexts` body — exactly `{name, prompt, opening_text}`.
     *
     * `opening_text` is intentionally OMITTED in this PR: `QuestionContext` carries no
     * opening-greeting field yet. PR3's `OpeningTextComposer` fills it (design D9/D11) —
     * this is a deliberate interim state, not a permanent shape.
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

        return $body;
    }

    /**
     * Build the `POST /v1/sessions/token` body — avatar identity lives HERE, never
     * on `/contexts` (PR2 D1/D2, delta spec "Avatar Identity Belongs to the
     * Session-Token Call").
     *
     * `array_replace_recursive`, NOT `array_merge`: the template emits
     * `avatar_persona.{voice_id, language}`; this method owns
     * `avatar_persona.context_id`. A shallow merge would replace the whole
     * `avatar_persona` node and silently drop voice/language — the C14
     * "operator sets a voice, hears no difference" failure at a new address.
     *
     * @wire-source legacy-demo/src/pages/api/interview/start.ts:206-221
     *
     * @return array<string, mixed>
     */
    private function buildSessionTokenBody(string $contextId): array
    {
        $templateFields = $this->allowlistedTemplateFields($this->activeTemplateConfig());

        $providerOwned = [
            'mode' => 'FULL',
            'is_sandbox' => false,
            'avatar_persona' => [
                'context_id' => $contextId,
            ],
        ];

        return array_replace_recursive($templateFields, $providerOwned);
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
     * @return array<int, array{speaker: string, text: string, ts: string}>
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

        $rows = $response->json('data', []);

        return array_map(function (array $row): array {
            return [
                'speaker' => $row['role'] === 'user' ? 'candidate' : 'avatar',
                'text' => (string) ($row['content'] ?? ''),
                'ts' => isset($row['time_ms'])
                    ? now()->subMilliseconds((int) $row['time_ms'])->toIso8601String()
                    : now()->toIso8601String(),
            ];
        }, is_array($rows) ? $rows : []);
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
