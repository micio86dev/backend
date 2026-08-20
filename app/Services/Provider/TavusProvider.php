<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Enums\ProviderFailureClass;
use App\Exceptions\ProviderException;
use App\Models\InterviewSession;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\AvatarTemplates\TemplatePayload;
use App\Support\Provider\ProviderErrorMessage;
use App\Support\Provider\TavusLanguage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tavus provider implementation (C7a Phase 7.6).
 *
 * Endpoints (Tavus API v2):
 *   POST https://tavusapi.com/v2/conversations           — create conversation
 *   POST https://tavusapi.com/v2/conversations/{id}/end  — end/teardown conversation
 *
 * @wire-source legacy-demo/src/pages/api/interview/start.ts:300-322 (create),
 *   legacy-demo/src/pages/api/interview/end.ts:59-62 (teardown) — PR5, design D8.
 *   The create body carries ONLY {replica_id, persona_id, conversational_context,
 *   custom_greeting, properties} (plus the C14 template merge below) — the
 *   `competency_code`/`question_index` keys sent by earlier revisions of this
 *   class were INVENTED and never accepted by the real Tavus contract.
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
        // @wire-source legacy-demo/src/pages/api/interview/start.ts:300-322 — CONFIRMED
        // (design/exploration phase, liveavatar-contract-alignment). `conversational_context`
        // is the real field name and the tavusapi.com/v2/conversations endpoint is correct.
        // PR5 (design D8): `competency_code`/`question_index` REMOVED — invented top-level
        // keys never accepted by the real Tavus contract (they do not appear in start.ts).
        $conversationBody = [];

        if ($ctx->systemPrompt !== null) {
            $conversationBody['conversational_context'] = $ctx->systemPrompt;
        }

        // PR3 (design D9/D11): `custom_greeting` — the avatar's first spoken line,
        // sourced from `QuestionContext.openingText` (composed by OpeningTextComposer,
        // same value HeygenProvider sends as `opening_text`). Omitted when null.
        // @wire-source legacy-demo/src/pages/api/interview/start.ts:300-322
        if ($ctx->openingText !== null) {
            $conversationBody['custom_greeting'] = $ctx->openingText;
        }

        // Hotfix 0.22.2 — root cause of the production 400 ("Either replica_id/
        // face_id or a persona_id/pal_id with a default replica specified must
        // be present"): avatar identity previously came ONLY from the org's
        // active `AvatarTemplate` (`TemplatePayload::tavus()`) — a source no
        // organization is required to have, so that mapping returned `[]` and
        // replica_id/persona_id were never sent. Pinned by
        // `ProviderContractFixtureTest`'s golden conversation body and
        // `AvatarLanguageTest`'s null-context case. Same three-layer precedence as
        // `HeygenProvider::buildSessionTokenBody()`, weakest to strongest:
        //   1. `$platformDefault` — this method's own floor. Always supplies
        //      replica_id/persona_id from `interview.tavus.*` config.
        //   2. `TemplatePayload::tavus(...)` — an org's active `AvatarTemplate`,
        //      when it sets a value. Overrides the platform default on a
        //      per-key basis. C14 avatar-template customization is preserved,
        //      not regressed by this fix.
        //   3. `$conversationBody` — conversational_context/custom_greeting,
        //      call-specific and never template-overridable.
        // C14: see HeygenProvider for the merge-not-assign reasoning. Persona
        // knobs are deliberately NOT here — they belong to the PAL, and sent on
        // a conversation Tavus ignores them silently.
        $conversationBody = array_replace_recursive(
            $this->platformDefaultConversationFields($ctx),
            TemplatePayload::tavus($this->activeTemplateConfig()),
            $conversationBody,
        );

        // PR6 (design D8): retry-with-backoff ONLY on a concurrency-limit
        // rejection, before surfacing 429 to the candidate. See
        // TavusConcurrencyGuard's docblock for why the demo's account-wide
        // reap is deliberately NOT ported alongside it.
        $guard = app(TavusConcurrencyGuard::class);
        $response = $guard->attempt(
            fn () => Http::withHeaders(['x-api-key' => $apiKey])->post(self::BASE_URL.'/conversations', $conversationBody),
            $apiKey,
        );

        if (! $response->successful()) {
            if ($guard->isConcurrencyLimited($response, $apiKey)) {
                // Retries exhausted (or disabled via config) and the rejection is
                // STILL a concurrency limit — surface 429, regardless of the raw
                // status Tavus returned for this specific rejection reason.
                throw new ProviderException(
                    "Tavus: conversation creation failed (HTTP {$response->status()}) — concurrency limit exhausted after retries",
                    ProviderFailureClass::Throttle,
                );
            }

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
     * The platform-default `/v2/conversations` fields (hotfix 0.22.2) — the
     * floor every request gets when the org has no active `AvatarTemplate`, or
     * when its template leaves a field unset.
     *
     * `replica_id`/`persona_id` come from `interview.tavus.{replica_id,
     * persona_id}` config (env `TAVUS_REPLICA_ID`/`TAVUS_PERSONA_ID`) —
     * omitted (not sent as `""`) when unset, matching `TemplatePayload`'s
     * "unset means absent" convention.
     *
     * @wire-source legacy-demo/src/pages/api/interview/start.ts:300-322
     *
     * @return array<string, mixed>
     */
    private function platformDefaultConversationFields(?QuestionContext $ctx = null): array
    {
        $body = [];

        // The avatar's spoken language follows the PROJECT (D2/D4). Tavus was the
        // harder half of this: the template used to be its ONLY language source,
        // so removing that without adding a platform default would have dropped
        // the language entirely — a regression wearing the shape of a fix.
        //
        // Written at `properties.language`, NOT top-level. The demonstrated-working
        // demo call nests it, and Tavus ignores a field at the wrong path in
        // silence. The mapper this replaces emitted it top-level, guarded by a
        // comment warning about exactly this failure mode one level up: its author
        // fixed the vocabulary and left the path wrong.
        $language = $ctx?->language ?? config('interview.tavus.language');

        if (is_string($language) && $language !== '') {
            $body['properties']['language'] = TavusLanguage::forWire($language);
        }

        $replicaId = config('interview.tavus.replica_id');

        if (is_string($replicaId) && $replicaId !== '') {
            $body['replica_id'] = $replicaId;
        }

        $personaId = config('interview.tavus.persona_id');

        if (is_string($personaId) && $personaId !== '') {
            $body['persona_id'] = $personaId;
        }

        return $body;
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
     *
     * PR5 (design D8): the real Tavus teardown contract is
     * `POST /v2/conversations/{id}/end` — NOT `DELETE /v2/conversations/{id}`,
     * which is not a documented endpoint anywhere in the reconstructed contract.
     *
     * @wire-source legacy-demo/src/pages/api/interview/end.ts:59-62
     */
    public function teardown(ProviderToken $token): void
    {
        if ($token->provider_session_ref === null) {
            return;
        }

        $apiKey = (string) config('interview.tavus.api_key', '');

        try {
            Http::withHeaders(['x-api-key' => $apiKey])
                ->post(self::BASE_URL.'/conversations/'.$token->provider_session_ref.'/end');
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
