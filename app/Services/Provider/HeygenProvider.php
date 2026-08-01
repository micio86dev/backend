<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Exceptions\ProviderException;
use App\Models\InterviewSession;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\AvatarTemplates\TemplatePayload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Issue a new HeyGen LiveAvatar session token.
     *
     * Steps:
     *   1. POST /contexts → context_id
     *   2. POST /sessions/token → access_token + session_id (= provider_session_ref)
     *
     * Called OUTSIDE any DB transaction.
     *
     * @throws ProviderException on HTTP 4xx/5xx; raw body redacted (task 14.3).
     */
    public function issue(InterviewSession $session, QuestionContext $ctx): ProviderToken
    {
        $apiKey = (string) config('interview.heygen.api_key', '');

        // Step 1: create context
        // C8 (RV-3): conditionally include system_prompt when the composed prompt is available.
        // When systemPrompt is null (C7a backward-compatible path), the key is OMITTED entirely —
        // the outbound body is identical to the pre-C8 shape.
        // NOTE: 'system_prompt' field name and the liveavatar.com/v1/contexts endpoint are INFERRED
        // from the C7a scaffold and are NOT verified against live provider docs. Client confirmation
        // of the real provider contract is required before live deploy. The PR-gated
        // HeygenProviderPayloadTest.php assertion catches any rename immediately (RV-3).
        $contextBody = [
            'competency_code' => $ctx->competencyCode,
            'question_index' => $ctx->questionIndex,
        ];

        if ($ctx->systemPrompt !== null) {
            $contextBody['system_prompt'] = $ctx->systemPrompt;
        }

        // C14: the organization's active avatar template, if it has one.
        //
        // Merged rather than assigned, so an organization with NO active
        // template sends exactly the body it sent before templates existed —
        // which is the state every tenant is in on the day this ships.
        //
        // The template's keys are additive: it can only add avatar_id,
        // avatar_persona, voice_settings and friends. It cannot overwrite
        // competency_code, question_index or system_prompt, because those are
        // the interview, not its appearance.
        $contextBody = array_merge(
            TemplatePayload::heygen($this->activeTemplateConfig()),
            $contextBody,
        );

        $ctxResponse = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->post(self::BASE_URL.'/contexts', $contextBody);

        if (! $ctxResponse->successful()) {
            $this->throwRedacted($apiKey, $ctxResponse->status(), 'context creation failed');
        }

        $contextId = $ctxResponse->json('data.context_id', '');

        // Step 2: issue session token
        $tokenResponse = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->post(self::BASE_URL.'/sessions/token', [
                'context_id' => $contextId,
            ]);

        if (! $tokenResponse->successful()) {
            $this->throwRedacted($apiKey, $tokenResponse->status(), 'session token issuance failed');
        }

        $data = $tokenResponse->json('data', []);
        $accessToken = $data['access_token'] ?? null;
        $sessionId = $data['session_id'] ?? null;

        if ($accessToken === null || $sessionId === null) {
            throw new ProviderException('HeyGen: malformed token response (missing access_token or session_id)', false);
        }

        return new ProviderToken(
            provider: 'heygen',
            token: $accessToken,
            conversation_url: null,
            provider_session_ref: $sessionId,
        );
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
     * Throw a ProviderException with the raw response body REDACTED.
     *
     * Security (task 14.3): the raw provider response may echo the API key or contain
     * internal endpoint details. The key and raw body MUST be stripped before logging or
     * re-throwing. Only a generic error message with the HTTP status is safe to surface.
     */
    private function throwRedacted(string $apiKey, int $status, string $context): never
    {
        $retryable = $status === 429;

        // Log at WARNING level with REDACTED body — never include raw response or key
        Log::warning('HeyGen: provider call failed', [
            'context' => $context,
            'status' => $status,
            // Deliberately NO 'response_body' — may contain HEYGEN_API_KEY
        ]);

        throw new ProviderException(
            "HeyGen: {$context} (HTTP {$status}) — provider response redacted",
            $retryable,
        );
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
