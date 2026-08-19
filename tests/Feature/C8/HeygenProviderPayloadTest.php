<?php

declare(strict_types=1);

/**
 * PR2 (2.2) — HeygenProvider payload shape assertion, rewritten off the real
 * LiveAvatar contract (delta spec `interview-session`, "HeyGen Context Creation
 * Wire Contract").
 *
 * PR-gated: a missing or renamed `prompt` field in the HeyGen /contexts POST body
 * MUST fail this suite. `system_prompt` is NOT a real wire field — it never
 * existed in the demo-proven contract; the real field name is `prompt`.
 *
 * Asserts:
 * (6.1) issue() with systemPrompt='TEST_PROMPT' → intercepted POST /contexts body
 *       is EXACTLY `{name, prompt}` — `prompt` === 'TEST_PROMPT'. `opening_text` is
 *       omitted in this PR (no data source yet; QuestionContext.openingText and
 *       OpeningTextComposer land in PR3, per design D11).
 * (6.2) issue() with systemPrompt=null → `prompt` key is absent (legacy path); body
 *       is `{name}` only.
 *
 * @wire-source legacy-demo/src/pages/api/interview/start.ts:246-267 (`createHeygenContext`)
 *
 * NOTE: this test proves our OWN request body shape, not that LiveAvatar accepts
 * it — that claim belongs to the fixture (L1/L2) and gated live smoke (L3) layers
 * added in PR4, per delta spec "Provider Wire Contracts Are Pinned Against
 * Recorded Real Responses".
 *
 * @group feature
 *
 * Spec: REQ HeyGen Context Creation Wire Contract (delta spec, interview-session)
 * REQ: HeygenProvider::issue() prompt forwarding (C8 Phase 6 — task 6.5, PR2 task 2.2)
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
        'id' => 99,
        'organization_id' => 1,
        'participant_id' => 1,
        'project_id' => 1,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => 1,
        'provider' => 'heygen',
        'provider_session_ref' => null,
        'status' => 'pending',
    ]);

    return $session;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('6.1 HeygenProvider::issue() with systemPrompt → POST /contexts body is exactly {name, prompt}', function (): void {
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-sp']], 200);
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

    // PR-gated assertion: `prompt` (the REAL wire field — @wire-source start.ts:255)
    // MUST be present and equal to the composed prompt. If the field is renamed or
    // omitted, this assertion fails on PR.
    expect($capturedBody)->toHaveKey('prompt', 'TEST_PROMPT');
    expect($capturedBody)->toHaveKey('name');
    expect($capturedBody)->not->toHaveKey('system_prompt');
    expect($capturedBody)->not->toHaveKey('competency_code');
    expect($capturedBody)->not->toHaveKey('question_index');
    // opening_text is omitted this PR — no data source until PR3's OpeningTextComposer.
    expect($capturedBody)->not->toHaveKey('opening_text');
});

test('6.2 HeygenProvider::issue() with null systemPrompt → POST /contexts body has no prompt key', function (): void {
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-null']], 200);
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

    // Legacy path: null systemPrompt → no `prompt` key in the outbound body.
    // `name` is always present (LiveAvatar uniqueness requirement, PR2 task 2.6).
    expect($capturedBody)->toHaveKey('name');
    expect($capturedBody)->not->toHaveKey('prompt');
    expect($capturedBody)->not->toHaveKey('system_prompt');
});
