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

        // C14: see HeygenProvider for the merge-not-assign reasoning. Persona
        // knobs are deliberately NOT here — they belong to the PAL, and sent on
        // a conversation Tavus ignores them silently.
        $conversationBody = array_merge(
            TemplatePayload::tavus($this->activeTemplateConfig()),
            $conversationBody,
        );

        $response = Http::withHeaders(['x-api-key' => $apiKey])
            ->post(self::BASE_URL.'/conversations', $conversationBody);

        if (! $response->successful()) {
            $this->throwRedacted($apiKey, $response, 'conversation creation failed');
        }

        $conversationId = $response->json('conversation_id');
        $conversationUrl = $response->json('conversation_url');

        if ($conversationId === null || $conversationUrl === null) {
            throw new ProviderException(
                'Tavus: malformed response (missing conversation_id or conversation_url)',
                ProviderFailureClass::Upstream,
            );
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
     * Throw a ProviderException, classified by HTTP status, with the raw response
     * body REDACTED — but the provider's own diagnostic message PRESERVED (PR1 D4, D6).
     *
     * Security (task 14.3): the key MUST be stripped before logging or re-throwing;
     * the full raw body is NEVER logged. See `HeygenProvider::throwRedacted()` for the
     * shared extraction contract (`ProviderErrorMessage::extract()`).
     */
    private function throwRedacted(string $apiKey, Response $response, string $context): never
    {
        $status = $response->status();
        $class = self::classify($status);
        $extracted = ProviderErrorMessage::extract($response->json(), $apiKey);

        Log::warning('Tavus: provider call failed', [
            'context' => $context,
            'status' => $status,
            'class' => $class->value,
            'provider_message' => $extracted,
            // Deliberately NO 'response_body' — may contain TAVUS_API_KEY
        ]);

        $suffix = $extracted !== null ? " — {$extracted}" : ' — provider response redacted';

        throw new ProviderException(
            "Tavus: {$context} (HTTP {$status}){$suffix}",
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
