<?php

declare(strict_types=1);

/**
 * HeygenProvider unit tests (C7a — Phase 7.3 RED).
 *
 * Asserts:
 * - Http::fake 200 → returns ProviderToken with non-null token + provider_session_ref; no key material.
 * - Http::fake 503 → throws ProviderException; raw response body REDACTED (key absent from message).
 * - Http::fake 429 → throws ProviderException with 'provider_busy' signal.
 * - reconcileTranscript() returns array of Utterance-like data.
 * - teardown() accepts only ProviderToken (typed, no raw-string overload).
 *
 * Tasks: 7.3 (RED)
 * REQ: HeygenProvider — provider secret non-exposure (C7a task 14.3)
 */

use App\Exceptions\ProviderException;
use App\Models\InterviewSession;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\ProviderToken;
use App\Services\Provider\QuestionContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    // Ensure API key is set for test environment
    config(['interview.heygen.api_key' => 'SUPER_SECRET_HEYGEN_KEY_12345']);
});

test('HeygenProvider::issue() on 200 returns ProviderToken with non-null token and provider_session_ref', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-abc']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'session-xyz',
                'access_token' => 'token-abc',
                'url' => 'https://webrtc.heygen.com/session-xyz',
            ],
        ], 200),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);

    $provider = new HeygenProvider;
    $token = $provider->issue($session, $ctx);

    expect($token)->toBeInstanceOf(ProviderToken::class);
    expect($token->provider)->toBe('heygen');
    expect($token->token)->not->toBeNull();
    expect($token->provider_session_ref)->not->toBeNull();
    // API key MUST NOT appear in the token
    expect($token->token)->not->toContain('SUPER_SECRET_HEYGEN_KEY_12345');
});

test('HeygenProvider::issue() on 5xx throws ProviderException with API key REDACTED from message', function (): void {
    // The provider 5xx response CONTAINS the API key (worst case — echoed by error handler)
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(
            ['error' => 'Unauthorized: SUPER_SECRET_HEYGEN_KEY_12345'],
            503
        ),
    ]);

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        $logMessages[] = (string) json_encode($message);
    });

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);

    $provider = new HeygenProvider;

    expect(fn () => $provider->issue($session, $ctx))
        ->toThrow(ProviderException::class);

    // Capture the exception message
    try {
        $provider->issue($session, $ctx);
    } catch (ProviderException $e) {
        // API key MUST be REDACTED from the exception message
        expect($e->getMessage())->not->toContain('SUPER_SECRET_HEYGEN_KEY_12345');
        // Log messages must NOT contain the raw key
        foreach ($logMessages as $msg) {
            expect($msg)->not->toContain('SUPER_SECRET_HEYGEN_KEY_12345');
        }
    }
});

test('HeygenProvider::issue() on 429 throws ProviderException with retryable signal', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'Too Many Requests'], 429),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;

    expect(fn () => $provider->issue($session, $ctx))
        ->toThrow(ProviderException::class);

    try {
        $provider->issue($session, $ctx);
    } catch (ProviderException $e) {
        expect($e->isRetryable())->toBeTrue();
    }
});

test('HeygenProvider::reconcileTranscript() returns array', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response([
            'data' => [
                ['role' => 'user',      'content' => 'Hello', 'time_ms' => 1000],
                ['role' => 'assistant', 'content' => 'Hi',    'time_ms' => 2000],
            ],
        ], 200),
    ]);

    $session = mockSession('heygen', 'ref-session-123');
    $provider = new HeygenProvider;
    $result = $provider->reconcileTranscript($session);

    expect($result)->toBeArray();
});

test('HeygenProvider::teardown() accepts ProviderToken (typed — no raw-string overload)', function (): void {
    Http::fake([
        '*liveavatar*/sessions*' => Http::response([], 200),
    ]);

    $token = ProviderToken::fromRef('heygen', 'session-ref-to-teardown');
    $provider = new HeygenProvider;

    // Should not throw
    $provider->teardown($token);

    expect(true)->toBeTrue(); // teardown succeeded
});

test('HeygenProvider::reconcileTranscript() with null provider_session_ref returns empty array', function (): void {
    $session = mockSession('heygen', null);
    $provider = new HeygenProvider;
    $result = $provider->reconcileTranscript($session);
    expect($result)->toBe([]);
});

test('HeygenProvider::reconcileTranscript() on non-200 returns empty array (best-effort)', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['error' => 'not found'], 404),
    ]);

    $session = mockSession('heygen', 'ref-fail-transcript');
    $provider = new HeygenProvider;
    $result = $provider->reconcileTranscript($session);
    expect($result)->toBe([]);
});

test('HeygenProvider::teardown() with null provider_session_ref is a no-op', function (): void {
    $token = new ProviderToken(provider: 'heygen', provider_session_ref: null);
    $provider = new HeygenProvider;

    $provider->teardown($token); // Must not throw or make any HTTP call
    Http::assertNothingSent();
    expect(true)->toBeTrue();
});

// ─── Helper ──────────────────────────────────────────────────────────────────

function mockSession(string $provider, ?string $ref = null): InterviewSession
{
    $session = new InterviewSession;
    $session->forceFill([
        'id' => 1,
        'organization_id' => 1,
        'participant_id' => 1,
        'project_id' => 1,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => 1,
        'provider' => $provider,
        'provider_session_ref' => $ref,
        'status' => 'pending',
    ]);

    return $session;
}
