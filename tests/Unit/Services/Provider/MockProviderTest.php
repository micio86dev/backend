<?php

declare(strict_types=1);

/**
 * MockProvider is the avatar provider behind test-mode interviews: it must never
 * reach a vendor, so it issues a vendor-less token and has nothing to reconcile
 * or tear down.
 */

use App\Models\InterviewSession;
use App\Services\Provider\MockProvider;
use App\Services\Provider\QuestionContext;

test('issue() returns a vendor-less mock token with a fresh session ref each time', function (): void {
    $provider = new MockProvider;
    $ctx = new QuestionContext('COL', 0);

    $first = $provider->issue(new InterviewSession, $ctx);
    $second = $provider->issue(new InterviewSession, $ctx);

    expect($first->provider)->toBe('mock')
        ->and($first->token)->toBeNull()
        ->and($first->conversation_url)->toBeNull()
        ->and($first->provider_session_ref)->toStartWith('mock_')
        ->and($second->provider_session_ref)->not->toBe($first->provider_session_ref);
});

test('a mock session has no transcript to reconcile', function (): void {
    expect((new MockProvider)->reconcileTranscript(new InterviewSession))->toBe([]);
});

test('tearing down a mock session succeeds because there is no vendor session to release', function (): void {
    $provider = new MockProvider;
    $token = $provider->issue(new InterviewSession, new QuestionContext('COL', 0));

    expect($provider->teardown($token))->toBeTrue();
});
