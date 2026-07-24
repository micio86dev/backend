<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Exceptions\ProviderException;
use App\Models\InterviewSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tavus provider implementation (C7a Phase 7.6).
 *
 * Endpoints (Tavus API v2):
 *   POST   https://tavusapi.com/v2/conversations        — create conversation
 *   DELETE https://tavusapi.com/v2/conversations/{id}   — end/teardown conversation
 *
 * reconcileTranscript() returns [] — Tavus does NOT provide a server-side transcript.
 * Live /utterance rows are kept as-is at /end (no REPLACE step).
 *
 * Security:
 * - API key lives ONLY in config('interview.tavus.api_key') (env TAVUS_API_KEY).
 * - Raw provider HTTP response bodies are ALWAYS REDACTED before re-throwing as
 *   ProviderException. The key MUST NEVER reach exception messages or logs (task 14.3).
 *
 * REQ: TavusProvider — provider session management (C7a)
 */
class TavusProvider implements ProviderSessionService
{
    private const BASE_URL = 'https://tavusapi.com/v2';

    /**
     * Issue a new Tavus conversation.
     *
     * Calls POST /v2/conversations → returns conversation_id + conversation_url.
     * Called OUTSIDE any DB transaction.
     *
     * @throws ProviderException on HTTP 4xx/5xx; raw body redacted (task 14.3).
     */
    public function issue(InterviewSession $session, QuestionContext $ctx): ProviderToken
    {
        $apiKey = (string) config('interview.tavus.api_key', '');

        // C8 (RV-3): conditionally include conversational_context when the composed prompt is available.
        // When systemPrompt is null (C7a backward-compatible path), the key is OMITTED entirely —
        // the outbound body is identical to the pre-C8 shape.
        // NOTE: 'conversational_context' field name and the tavusapi.com/v2/conversations endpoint
        // are INFERRED from the C7a scaffold and are NOT verified against live provider docs. Client
        // confirmation of the real provider contract is required before live deploy. The PR-gated
        // TavusProviderPayloadTest.php assertion catches any rename immediately (RV-3).
        $conversationBody = [
            'competency_code' => $ctx->competencyCode,
            'question_index' => $ctx->questionIndex,
        ];

        if ($ctx->systemPrompt !== null) {
            $conversationBody['conversational_context'] = $ctx->systemPrompt;
        }

        $response = Http::withHeaders(['x-api-key' => $apiKey])
            ->post(self::BASE_URL.'/conversations', $conversationBody);

        if (! $response->successful()) {
            $this->throwRedacted($apiKey, $response->status(), 'conversation creation failed');
        }

        $conversationId = $response->json('conversation_id');
        $conversationUrl = $response->json('conversation_url');

        if ($conversationId === null || $conversationUrl === null) {
            throw new ProviderException('Tavus: malformed response (missing conversation_id or conversation_url)', false);
        }

        return new ProviderToken(
            provider: 'tavus',
            token: null,
            conversation_url: (string) $conversationUrl,
            provider_session_ref: (string) $conversationId,
        );
    }

    /**
     * No-op: Tavus does not provide a server-side transcript reconciliation.
     *
     * Live /utterance rows submitted via POST /utterance are kept as-is at /end.
     *
     * @return array<never, never> always empty
     */
    public function reconcileTranscript(InterviewSession $session): array
    {
        return [];
    }

    /**
     * Teardown (end) a Tavus conversation.
     *
     * Best-effort — failure is logged but non-fatal.
     * ALWAYS takes a typed ProviderToken (no raw-string overload) per WARNING-6.
     */
    public function teardown(ProviderToken $token): void
    {
        if ($token->provider_session_ref === null) {
            return;
        }

        $apiKey = (string) config('interview.tavus.api_key', '');

        try {
            Http::withHeaders(['x-api-key' => $apiKey])
                ->delete(self::BASE_URL.'/conversations/'.$token->provider_session_ref);
        } catch (\Throwable $e) {
            Log::warning('Tavus: teardown failed', [
                'provider_session_ref' => $token->provider_session_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Throw a ProviderException with the raw response body REDACTED.
     *
     * Security (task 14.3): the key and raw body are stripped before re-throwing.
     */
    private function throwRedacted(string $apiKey, int $status, string $context): never
    {
        $retryable = $status === 429;

        Log::warning('Tavus: provider call failed', [
            'context' => $context,
            'status' => $status,
            // Deliberately NO 'response_body' — may contain TAVUS_API_KEY
        ]);

        throw new ProviderException(
            "Tavus: {$context} (HTTP {$status}) — provider response redacted",
            $retryable,
        );
    }
}
