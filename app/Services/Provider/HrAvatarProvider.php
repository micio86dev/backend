<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Enums\ProviderFailureClass;
use App\Exceptions\ProviderException;
use App\Models\InterviewSession;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\Interview\SessionLiveClock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * HR-Avatar provider (hr-avatar-adapter): one BEAI session issue is one HR-Avatar conversation.
 *
 * Endpoints (HR-Avatar `/v1`, secret key as the bearer credential):
 *   POST /v1/conversations           — create; returns the `conv_...` id and a one-time `client_secret`
 *   POST /v1/conversations/{id}/end  — idempotent end
 *
 * The `client_secret` is the browser's credential: it travels to the candidate as the token and is
 * persisted nowhere. BEAI's own utterance rows stay the transcript of record, so
 * `reconcileTranscript()` returns `[]` and nothing is fetched back.
 *
 * Diagnostics are deliberately blind: an upstream `detail`, body or prompt is never read into a
 * message or a log line. Only the status class and a Problem+JSON `code` that matches a strict
 * allowlist pattern are reported, so there is nothing to redact.
 */
final class HrAvatarProvider implements ProviderSessionService
{
    private const SYSTEM_PROMPT_MAX_CHARS = 32000;

    private const OPENING_TEXT_MAX_CHARS = 4000;

    private const MIN_DURATION_SECONDS = 30;

    private const MAX_DURATION_SECONDS = 3600;

    private const CONVERSATION_REF = '/^conv_[0-9A-Za-z]+$/';

    public function __construct(
        private readonly ActiveTemplateResolver $templates,
        private readonly SessionLiveClock $clock,
    ) {}

    /**
     * @throws ProviderException ClientError before any request when the request cannot be well formed or the
     *                           server answers another 4xx; Throttle on 429; Upstream otherwise.
     */
    public function issue(InterviewSession $session, QuestionContext $ctx): ProviderToken
    {
        $body = $this->conversationBody($session, $ctx);

        try {
            $response = HrAvatarConnection::client()->post('/v1/conversations', $body);
        } catch (ConnectionException) {
            $this->fail('issue', ProviderFailureClass::Upstream, 'connection', null, 'issue failed (connection error)');
        }

        if (! $response->successful()) {
            $this->failFromResponse('issue', $response);
        }

        $id = $response->json('id');
        $secret = $response->json('client_secret');

        if ($response->status() !== 201 || ! is_string($id) || preg_match(self::CONVERSATION_REF, $id) !== 1 || ! is_string($secret) || $secret === '') {
            $this->fail('issue', ProviderFailureClass::Upstream, $this->statusClass($response), null, 'issue failed (malformed response)');
        }

        return new ProviderToken(
            provider: HrAvatarConnection::PROVIDER,
            token: $secret,
            provider_session_ref: $id,
        );
    }

    /**
     * No-op: BEAI's live utterance rows are kept as they are, nothing is fetched from HR-Avatar.
     *
     * @return array<never, never>
     */
    public function reconcileTranscript(InterviewSession $session): array
    {
        return [];
    }

    /**
     * End the conversation. Never throws: a failure is logged without the secret and reported as false.
     * A reference that is not a conversation id sends nothing (it also keeps path characters out of the URL).
     */
    public function teardown(ProviderToken $token): bool
    {
        $ref = $token->provider_session_ref;

        if ($ref === null || preg_match(self::CONVERSATION_REF, $ref) !== 1) {
            return true;
        }

        if (! HrAvatarConnection::isAvailable()) {
            $this->log('teardown', 'none', 'integration_unavailable', ProviderFailureClass::ClientError);

            return false;
        }

        try {
            $response = HrAvatarConnection::client()->post('/v1/conversations/'.$ref.'/end');
        } catch (ConnectionException) {
            $this->log('teardown', 'connection', null, ProviderFailureClass::Upstream);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        $this->log('teardown', $this->statusClass($response), $this->safeCode($response), $this->classify($response->status()));

        return false;
    }

    /**
     * The exactly-seven-member create body. Anything that would make it invalid is refused here, before a
     * request, and never repaired: a prompt is not truncated, a missing config id is not guessed.
     *
     * @return array<string, mixed>
     */
    private function conversationBody(InterviewSession $session, QuestionContext $ctx): array
    {
        if (! HrAvatarConnection::isAvailable()) {
            $this->refuse('integration_unavailable');
        }

        $configId = $this->templates->resolve(HrAvatarConnection::PROVIDER, $session->project_id)?->hr_avatar_config_id;

        if ($configId === null || $configId === '') {
            $this->refuse('config_id_missing');
        }

        if ($ctx->systemPrompt === null || mb_strlen($ctx->systemPrompt) > self::SYSTEM_PROMPT_MAX_CHARS) {
            $this->refuse('system_prompt_invalid');
        }

        if ($ctx->openingText === null || mb_strlen($ctx->openingText) > self::OPENING_TEXT_MAX_CHARS) {
            $this->refuse('opening_text_invalid');
        }

        if ($ctx->language === null || preg_match('/^[a-z]{2}$/', $ctx->language) !== 1) {
            $this->refuse('language_invalid');
        }

        return [
            'avatar_config_id' => $configId,
            'system_prompt' => $ctx->systemPrompt,
            'opening_text' => $ctx->openingText,
            'language' => $ctx->language,
            'max_duration_seconds' => max(
                self::MIN_DURATION_SECONDS,
                min(self::MAX_DURATION_SECONDS, $this->clock->resolveMaxSeconds($session)),
            ),
            'external_ref' => 'session_'.$session->id,
            // An object, not an array: PHP would serialize an empty array as `[]`.
            'metadata' => new \stdClass,
        ];
    }

    private function refuse(string $reason): never
    {
        $this->fail('issue', ProviderFailureClass::ClientError, 'none', $reason, "issue failed ({$reason})");
    }

    private function failFromResponse(string $operation, Response $response): never
    {
        $code = $this->safeCode($response);

        $this->fail($operation, $this->classify($response->status()), $this->statusClass($response), $code, sprintf(
            '%s failed (status %s%s)',
            $operation,
            $this->statusClass($response),
            $code === null ? '' : ", code {$code}",
        ));
    }

    private function fail(string $operation, ProviderFailureClass $class, string $statusClass, ?string $code, string $message): never
    {
        $this->log($operation, $statusClass, $code, $class);

        throw new ProviderException('hr_avatar: '.$message, $class);
    }

    private function classify(int $status): ProviderFailureClass
    {
        return match (true) {
            $status === 429 => ProviderFailureClass::Throttle,
            $status >= 500 => ProviderFailureClass::Upstream,
            default => ProviderFailureClass::ClientError,
        };
    }

    private function log(string $operation, string $statusClass, ?string $code, ProviderFailureClass $class): void
    {
        Log::warning('HR-Avatar: provider call failed', [
            'operation' => $operation,
            'status_class' => $statusClass,
            'code' => $code,
            'class' => $class->value,
        ]);
    }

    private function statusClass(Response $response): string
    {
        return intdiv($response->status(), 100).'xx';
    }

    /** The Problem+JSON `code`, only when it matches the allowlist pattern; upstream text is never echoed. */
    private function safeCode(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) && preg_match('/^[a-z_]{1,64}$/', $code) === 1 ? $code : null;
    }
}
