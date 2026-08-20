<?php

declare(strict_types=1);

/**
 * PR4 — Provider Wire Contracts Are Pinned Against Recorded Real Responses
 * (delta spec, interview-session; design D10, layer L1 + L2).
 *
 * **L1 — response fixtures** (this file's first four tests): each fixture under
 * `tests/Fixtures/Provider/{liveavatar,tavus}/*.json` is DOCS-VERIFIED against the
 * reconstructed real contract (`legacy-demo/src/pages/api/interview/{start,end}.ts`
 * — see the `@wire-source` citation on each test) — it is NOT a captured live HTTP
 * recording (no real credentials exist in this environment to capture one). These
 * tests prove our PARSING code correctly extracts the fields it needs from a
 * response shaped exactly like the real contract. They prove NOTHING about
 * whether the provider accepts our OUTBOUND request — that is L2 (below) and L3
 * (`php artisan interview:smoke-check`, gated, never run in CI).
 *
 * **L2 — outbound golden body** (the final test): proves our outbound `/contexts`
 * body has not CHANGED since a human last verified it, NOT that it is correct.
 * This is the layer that would have put the invented `{competency_code,
 * question_index, system_prompt}` shape in front of a C7a reviewer as literal
 * JSON, before it ever reached production.
 *
 * @group feature
 *
 * Spec: REQ Provider Wire Contracts Are Pinned Against Recorded Real Responses (delta spec, interview-session)
 * REQ: ProviderContractFixtureTest (PR4 tasks 4.7–4.9 — design D10)
 */

use App\Models\InterviewSession;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\QuestionContext;
use App\Services\Provider\TavusProvider;
use Illuminate\Support\Facades\Http;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function fixtureContractSession(string $provider): InterviewSession
{
    $session = new InterviewSession;
    $session->forceFill([
        'id' => 777,
        'organization_id' => 1,
        'participant_id' => 1,
        'project_id' => 1,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => 1,
        'provider' => $provider,
        'provider_session_ref' => 'fixture-ref-777',
        'status' => 'pending',
    ]);

    return $session;
}

function loadProviderFixture(string $relativePath): array
{
    return json_decode(
        file_get_contents(base_path('tests/Fixtures/Provider/'.$relativePath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

// ─── L1: response fixtures prove PARSING, not acceptance ─────────────────────

test('L1: HeygenProvider::issue() correctly parses a docs-verified /contexts + /sessions/token fixture pair', function (): void {
    // @wire-source legacy-demo/src/pages/api/interview/start.ts:246-267 (contexts),
    // :206-221 (sessions/token) — docs-verified against the reconstructed contract,
    // NOT a captured live recording (no real credentials in this environment).
    $contextFixture = loadProviderFixture('liveavatar/context_create_response.json');
    $tokenFixture = loadProviderFixture('liveavatar/session_token_response.json');

    Http::fake([
        '*liveavatar*/contexts*' => Http::response($contextFixture, 200),
        '*liveavatar*/sessions/token*' => Http::response($tokenFixture, 200),
    ]);

    $session = fixtureContractSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0, systemPrompt: 'p', promptVersion: 'v1');

    $token = (new HeygenProvider)->issue($session, $ctx);

    // Proves our parsing reads `data.id` (contexts) and `data.session_token` /
    // `data.session_id` (sessions/token) correctly — nothing about acceptance.
    expect($token->token)->toBe($tokenFixture['data']['session_token']);
    expect($token->provider_session_ref)->toBe($tokenFixture['data']['session_id']);
});

test('L1: HeygenProvider::reconcileTranscript() correctly parses a docs-verified transcript fixture', function (): void {
    // @wire-source legacy-demo/src/pages/api/interview/end.ts:76-84 — docs-verified.
    $fixture = loadProviderFixture('liveavatar/transcript_response.json');

    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response($fixture, 200),
    ]);

    $session = fixtureContractSession('heygen');
    $result = (new HeygenProvider)->reconcileTranscript($session);

    expect($result)->toHaveCount(3);
    expect($result[0]['speaker'])->toBe('candidate'); // role: user
    expect($result[1]['speaker'])->toBe('avatar');     // role: assistant
    expect($result[0]['text'])->toBe($fixture['data']['transcript_data'][0]['transcript']);
});

test('L1: TavusProvider::issue() correctly parses a docs-verified /conversations fixture', function (): void {
    // @wire-source legacy-demo/src/pages/api/interview/start.ts:362-363 — the ids
    // are read TOP-LEVEL (not nested under `data`) — docs-verified.
    $fixture = loadProviderFixture('tavus/conversation_create_response.json');

    Http::fake([
        '*tavusapi*/conversations*' => Http::response($fixture, 200),
    ]);

    $session = fixtureContractSession('tavus');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0, systemPrompt: 'p', promptVersion: 'v1');

    $token = (new TavusProvider)->issue($session, $ctx);

    expect($token->provider_session_ref)->toBe($fixture['conversation_id']);
    expect($token->conversation_url)->toBe($fixture['conversation_url']);
});

// ─── L2: outbound golden body — proves "unchanged", not "correct" ────────────

test('L2: HeyGen /contexts outbound body matches the golden JSON verified against start.ts:246-267', function (): void {
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/contexts')) {
            $capturedBody = $request->data();

            return Http::response(['data' => ['id' => 'ctx-golden']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'sid-golden', 'session_token' => 'tok-golden']], 200);
        }

        return Http::response([], 200);
    });

    $session = fixtureContractSession('heygen');
    $ctx = new QuestionContext(
        competencyCode: 'PRS',
        questionIndex: 0,
        systemPrompt: 'TEST_PROMPT',
        promptVersion: 'v1',
        openingText: 'TEST_OPENING',
    );

    (new HeygenProvider)->issue($session, $ctx);

    // `name` is per-request unique (session id + ULID, task 2.6) — asserted by
    // pattern, excluded from the golden comparison (which covers the STATIC part
    // of the body only).
    expect($capturedBody['name'])->toMatch('/^beai-777-[0-9A-Za-z]{26}$/');
    unset($capturedBody['name']);

    $golden = loadProviderFixture('liveavatar/contexts_request_golden.json');

    // assertEqualsCanonicalizing (design D10 L2): proves the body has not CHANGED
    // since a human verified it — NOT that LiveAvatar accepts it. That claim
    // belongs to L3 (`interview:smoke-check`, gated, never run in CI).
    $this->assertEqualsCanonicalizing($golden, $capturedBody);
});

test('L2: Tavus /conversations outbound body matches the golden JSON verified against start.ts:300-322 (PR5)', function (): void {
    // PR4 scoped this test OUT because Tavus's body still carried PR5's invented
    // `competency_code`/`question_index` keys — asserting a golden body known to
    // be wrong would have encoded the bug. PR5 corrects the body; this closes
    // that gap (design D10, L2).
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/conversations')) {
            $capturedBody = $request->data();

            return Http::response([
                'conversation_id' => 'conv-golden',
                'conversation_url' => 'https://tavus.io/conv-golden',
            ], 200);
        }

        return Http::response([], 200);
    });

    $session = fixtureContractSession('tavus');
    $ctx = new QuestionContext(
        competencyCode: 'PRS',
        questionIndex: 0,
        systemPrompt: 'TEST_PROMPT',
        promptVersion: 'v1',
        openingText: 'TEST_OPENING',
    );

    (new TavusProvider)->issue($session, $ctx);

    $golden = loadProviderFixture('tavus/conversations_request_golden.json');

    // No active avatar template exists in this test (C14: empty config → empty
    // payload), so `replica_id`/`persona_id`/`properties.*` are legitimately
    // absent — the golden body covers only the fields Tavus always receives.
    $this->assertEqualsCanonicalizing($golden, $capturedBody);
});
