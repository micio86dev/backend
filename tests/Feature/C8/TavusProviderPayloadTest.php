<?php

declare(strict_types=1);

/**
 * RED — Tasks 6.3–6.4: TavusProvider payload shape assertion (C8 Phase 6).
 *
 * PR-gated: a missing or renamed `conversational_context` field in the Tavus
 * /conversations POST body MUST fail this suite.
 *
 * Asserts:
 * (6.3) issue() with systemPrompt='TEST_PROMPT' → intercepted POST /conversations body
 *       CONTAINS 'conversational_context' => 'TEST_PROMPT'.
 * (6.4) issue() with systemPrompt=null → POST /conversations body does NOT include
 *       'conversational_context' key (legacy C7a shape preserved).
 *
 * RV-3 NOTE: 'conversational_context' field name and the tavusapi.com/v2/conversations
 * endpoint are INFERRED from the C7a scaffold. Client confirmation of the live
 * provider contract is required before live deploy. This PR-gated assertion
 * catches any rename immediately.
 *
 * @group feature
 *
 * Spec: REQ Provider Payload Contract (C-1) · RV-3 PR-gated assertion.
 * REQ: TavusProvider::issue() conversational_context forwarding (C8 Phase 6 — task 6.6)
 */

use App\Models\InterviewSession;
use App\Services\Provider\QuestionContext;
use App\Services\Provider\TavusProvider;
use Illuminate\Support\Facades\Http;

// ─── Helper ──────────────────────────────────────────────────────────────────

function c8TavusMockSession(): InterviewSession
{
    $session = new InterviewSession;
    $session->forceFill([
        'id' => 98,
        'organization_id' => 1,
        'participant_id' => 1,
        'project_id' => 1,
        'question_index' => 0,
        'competency_code' => 'COL',
        'framework_version_id' => 1,
        'provider' => 'tavus',
        'provider_session_ref' => null,
        'status' => 'pending',
    ]);

    return $session;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('6.3 TavusProvider::issue() with systemPrompt → POST /conversations body CONTAINS conversational_context key', function (): void {
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/conversations')) {
            $capturedBody = $request->data();

            return Http::response([
                'conversation_id' => 'conv-sp',
                'conversation_url' => 'https://tavus.io/conv-sp',
            ], 200);
        }

        return Http::response([], 200);
    });

    $session = c8TavusMockSession();
    $ctx = new QuestionContext(
        competencyCode: 'COL',
        questionIndex: 0,
        systemPrompt: 'TEST_PROMPT',
        promptVersion: 'conv-2026-07-23',
    );

    $provider = new TavusProvider;
    $provider->issue($session, $ctx);

    // RV-3 PR-gated assertion: conversational_context MUST be present and equal to the composed prompt.
    // If the field is renamed or omitted, this assertion fails on PR.
    expect($capturedBody)->toHaveKey('conversational_context');
    expect($capturedBody['conversational_context'])->toBe('TEST_PROMPT');
});

test('6.4 TavusProvider::issue() with null systemPrompt → POST /conversations body does NOT include conversational_context key', function (): void {
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/conversations')) {
            $capturedBody = $request->data();

            return Http::response([
                'conversation_id' => 'conv-null',
                'conversation_url' => 'https://tavus.io/conv-null',
            ], 200);
        }

        return Http::response([], 200);
    });

    $session = c8TavusMockSession();
    $ctx = new QuestionContext(
        competencyCode: 'COL',
        questionIndex: 0,
        // systemPrompt defaults to null — legacy C7a path
    );

    $provider = new TavusProvider;
    $provider->issue($session, $ctx);

    // Legacy path: null systemPrompt → no conversational_context key in the outbound body.
    expect($capturedBody)->not->toHaveKey('conversational_context');
});
