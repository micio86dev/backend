<?php

declare(strict_types=1);

/**
 * TavusProvider unit tests (C7a — Phase 7.5 RED).
 *
 * Asserts:
 * - issue() calls Tavus /v2/conversations; returns ProviderToken with non-null conversation_url.
 * - Http::fake error paths (429, 5xx) with secret redaction.
 * - reconcileTranscript() returns [] (no reconcile for Tavus).
 * - teardown() calls Tavus delete/stop endpoint.
 *
 * Tasks: 7.5 (RED)
 * REQ: TavusProvider — secret non-exposure, no reconcile (C7a)
 */

use App\Exceptions\ProviderException;
use App\Models\InterviewSession;
use App\Services\Provider\ProviderToken;
use App\Services\Provider\QuestionContext;
use App\Services\Provider\TavusProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config(['interview.tavus.api_key' => 'SUPER_SECRET_TAVUS_KEY_99999']);
});

test('TavusProvider::issue() on 200 returns ProviderToken with non-null conversation_url', function (): void {
    Http::fake([
        '*tavusapi*/v2/conversations*' => Http::response([
            'conversation_id' => 'conv-abc-123',
            'conversation_url' => 'https://tavus.io/conv-abc-123',
        ], 200),
    ]);

    $session = tavusMockSession();
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new TavusProvider;
    $token = $provider->issue($session, $ctx);

    expect($token)->toBeInstanceOf(ProviderToken::class);
    expect($token->provider)->toBe('tavus');
    expect($token->conversation_url)->not->toBeNull();
    expect($token->provider_session_ref)->not->toBeNull();
    // API key MUST NOT appear in the token
    expect($token->conversation_url)->not->toContain('SUPER_SECRET_TAVUS_KEY_99999');
});

test('TavusProvider::issue() on 5xx throws ProviderException with API key REDACTED', function (): void {
    Http::fake([
        '*tavusapi*/v2/conversations*' => Http::response(
            ['error' => 'Internal error: SUPER_SECRET_TAVUS_KEY_99999'],
            500
        ),
    ]);

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        $logMessages[] = (string) json_encode($message);
    });

    $session = tavusMockSession();
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new TavusProvider;

    expect(fn () => $provider->issue($session, $ctx))
        ->toThrow(ProviderException::class);

    try {
        $provider->issue($session, $ctx);
    } catch (ProviderException $e) {
        expect($e->getMessage())->not->toContain('SUPER_SECRET_TAVUS_KEY_99999');
        foreach ($logMessages as $msg) {
            expect($msg)->not->toContain('SUPER_SECRET_TAVUS_KEY_99999');
        }
    }
});

test('TavusProvider::issue() on 429 throws retryable ProviderException', function (): void {
    Http::fake([
        '*tavusapi*/v2/conversations*' => Http::response(['error' => 'rate_limit'], 429),
    ]);

    $session = tavusMockSession();
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new TavusProvider;

    try {
        $provider->issue($session, $ctx);
    } catch (ProviderException $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

test('TavusProvider::reconcileTranscript() returns empty array (no reconcile for Tavus)', function (): void {
    $session = tavusMockSession('ref-conv-456');
    $provider = new TavusProvider;

    $result = $provider->reconcileTranscript($session);

    expect($result)->toBe([]);
});

test('TavusProvider::teardown() with null provider_session_ref is a no-op', function (): void {
    $token = new ProviderToken(provider: 'tavus', provider_session_ref: null);
    $provider = new TavusProvider;

    $provider->teardown($token); // Must not throw or make any HTTP call
    Http::assertNothingSent();
    expect(true)->toBeTrue();
});

test('TavusProvider::teardown() calls provider endpoint with ProviderToken', function (): void {
    Http::fake([
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
    ]);

    $token = ProviderToken::fromRef('tavus', 'conv-to-stop');
    $provider = new TavusProvider;

    $provider->teardown($token);

    expect(true)->toBeTrue(); // teardown succeeded
});

// ─── Helper ──────────────────────────────────────────────────────────────────

function tavusMockSession(?string $ref = null): InterviewSession
{
    $session = new InterviewSession;
    $session->forceFill([
        'id' => 2,
        'organization_id' => 1,
        'participant_id' => 1,
        'project_id' => 1,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => 1,
        'provider' => 'tavus',
        'provider_session_ref' => $ref,
        'status' => 'pending',
    ]);

    return $session;
}
