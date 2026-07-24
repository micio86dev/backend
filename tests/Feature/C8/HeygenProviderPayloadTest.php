<?php

declare(strict_types=1);

/**
 * RED — Tasks 6.1–6.2: HeygenProvider payload shape assertion (C8 Phase 6).
 *
 * PR-gated: a missing or renamed `system_prompt` field in the HeyGen
 * /contexts POST body MUST fail this suite.
 *
 * Asserts:
 * (6.1) issue() with systemPrompt='TEST_PROMPT' → intercepted POST /contexts body
 *       CONTAINS 'system_prompt' => 'TEST_PROMPT'.
 * (6.2) issue() with systemPrompt=null → POST /contexts body does NOT include
 *       'system_prompt' key (legacy C7a shape preserved).
 *
 * RV-3 NOTE: 'system_prompt' field name and the liveavatar.com/v1/contexts
 * endpoint are INFERRED from the C7a scaffold. Client confirmation of the live
 * provider contract is required before live deploy. This PR-gated assertion
 * catches any rename immediately.
 *
 * @group feature
 *
 * Spec: REQ Provider Payload Contract (C-1) · RV-3 PR-gated assertion.
 * REQ: HeygenProvider::issue() system_prompt forwarding (C8 Phase 6 — task 6.5)
 */

use App\Models\InterviewSession;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\QuestionContext;
use Illuminate\Support\Facades\Http;

// ─── Helper ──────────────────────────────────────────────────────────────────

function heygenMockSession(): InterviewSession
{
    $session = new InterviewSession;
    $session->forceFill([
        'id'                   => 99,
        'organization_id'      => 1,
        'participant_id'       => 1,
        'project_id'           => 1,
        'question_index'       => 0,
        'competency_code'      => 'PRS',
        'framework_version_id' => 1,
        'provider'             => 'heygen',
        'provider_session_ref' => null,
        'status'               => 'pending',
    ]);
    return $session;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('6.1 HeygenProvider::issue() with systemPrompt → POST /contexts body CONTAINS system_prompt key', function (): void {
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedBody = $request->data();
            return Http::response(['data' => ['context_id' => 'ctx-sp']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'sid-sp', 'access_token' => 'tok-sp']], 200);
        }
        return Http::response([], 200);
    });

    $session = heygenMockSession();
    $ctx = new QuestionContext(
        competencyCode: 'PRS',
        questionIndex: 0,
        systemPrompt: 'TEST_PROMPT',
        promptVersion: 'conv-2026-07-23',
    );

    $provider = new HeygenProvider;
    $provider->issue($session, $ctx);

    // RV-3 PR-gated assertion: system_prompt MUST be present and equal to the composed prompt.
    // If the field is renamed or omitted, this assertion fails on PR.
    expect($capturedBody)->toHaveKey('system_prompt');
    expect($capturedBody['system_prompt'])->toBe('TEST_PROMPT');
});

test('6.2 HeygenProvider::issue() with null systemPrompt → POST /contexts body does NOT include system_prompt key', function (): void {
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedBody = $request->data();
            return Http::response(['data' => ['context_id' => 'ctx-null']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'sid-null', 'access_token' => 'tok-null']], 200);
        }
        return Http::response([], 200);
    });

    $session = heygenMockSession();
    $ctx = new QuestionContext(
        competencyCode: 'PRS',
        questionIndex: 0,
        // systemPrompt defaults to null — legacy C7a path
    );

    $provider = new HeygenProvider;
    $provider->issue($session, $ctx);

    // Legacy path: null systemPrompt → no system_prompt key in the outbound body.
    expect($capturedBody)->not->toHaveKey('system_prompt');
});
