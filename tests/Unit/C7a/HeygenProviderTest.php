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

use App\Enums\ProviderFailureClass;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderTranscriptShapeException;
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\ProviderToken;
use App\Services\Provider\QuestionContext;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    // Ensure API key is set for test environment
    config(['interview.heygen.api_key' => 'SUPER_SECRET_HEYGEN_KEY_12345']);
});

test('HeygenProvider::issue() on 200 returns ProviderToken with non-null token and provider_session_ref', function (): void {
    // PR2 (D1): the real LiveAvatar response reads the context id from `data.id`,
    // NOT `data.context_id` (a field that does not exist in the real contract —
    // @wire-source legacy-demo/src/pages/api/interview/start.ts:265).
    $capturedTokenBody = [];
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-abc']], 200),
        '*liveavatar*/sessions/token*' => function ($request) use (&$capturedTokenBody) {
            $capturedTokenBody = $request->data();

            return Http::response([
                'data' => [
                    'session_id' => 'session-xyz',
                    'session_token' => 'token-abc',
                    'url' => 'https://webrtc.heygen.com/session-xyz',
                ],
            ], 200);
        },
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

    // PR2 (D1): the context id read from `data.id` MUST reach /sessions/token under
    // avatar_persona.context_id — proves the response-key rename actually threads through.
    expect($capturedTokenBody)->toHaveKey('avatar_persona.context_id', 'ctx-abc');
});

test('HeygenProvider::issue() names the context beai-{session_id}-{ulid}; two consecutive calls are distinct (PR2 D3, F3)', function (): void {
    // F3: handleResumeInCorso() calls issue() again for the SAME interview_session_id
    // on resume. A session-id-only name (`beai-{id}`) collides on the SECOND call
    // exactly like legacy-demo/src/pages/api/interview/start.ts:250-252 documents.
    // The ULID suffix is what makes each issue() call distinct.
    $capturedNames = [];
    Http::fake([
        '*liveavatar*/contexts*' => function ($request) use (&$capturedNames) {
            $capturedNames[] = $request->data()['name'];

            return Http::response(['data' => ['id' => 'ctx-'.count($capturedNames)]], 200);
        },
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => ['session_id' => 'sid', 'session_token' => 'tok'],
        ], 200),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;

    $provider->issue($session, $ctx);
    $provider->issue($session, $ctx); // simulates the resume re-issue (same session)

    expect($capturedNames)->toHaveCount(2);
    // No candidate PII — only the opaque interview_session_id and a ULID.
    expect($capturedNames[0])->toStartWith("beai-{$session->id}-");
    expect($capturedNames[1])->toStartWith("beai-{$session->id}-");
    // The two names are distinct, even for the SAME session id.
    expect($capturedNames[0])->not->toBe($capturedNames[1]);
});

test('HeygenProvider::issue() merges the template into /sessions/token recursively — voice_id, language, and context_id all survive together (PR2 D1)', function (): void {
    $template = new AvatarTemplate;
    $template->forceFill([
        'config' => [
            'avatarId' => 'av-template-1',
            'voiceId' => 'voice-template-1',
            'language' => 'it',
        ],
    ]);

    app()->instance(
        ActiveTemplateResolver::class,
        new class($template)
        {
            public function __construct(private readonly AvatarTemplate $template) {}

            public function resolve(): AvatarTemplate
            {
                return $this->template;
            }
        },
    );

    $capturedTokenBody = [];
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-merge']], 200),
        '*liveavatar*/sessions/token*' => function ($request) use (&$capturedTokenBody) {
            $capturedTokenBody = $request->data();

            return Http::response(['data' => ['session_id' => 'sid-merge', 'session_token' => 'tok-merge']], 200);
        },
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;
    $provider->issue($session, $ctx);

    // D1 invariant: array_replace_recursive, NOT array_merge. A shallow merge would
    // replace the whole avatar_persona node with {context_id} alone and silently
    // drop voice_id/language — the C14 "operator sets a voice, hears no difference"
    // failure at a new address.
    expect($capturedTokenBody)->toHaveKey('avatar_persona.voice_id', 'voice-template-1');
    expect($capturedTokenBody)->toHaveKey('avatar_persona.language', 'it');
    expect($capturedTokenBody)->toHaveKey('avatar_persona.context_id', 'ctx-merge');
    expect($capturedTokenBody)->toHaveKey('avatar_id', 'av-template-1');

    app()->forgetInstance(ActiveTemplateResolver::class);
});

test('HeygenProvider::issue() drops unproven template fields from /sessions/token by default (PR2 D2)', function (): void {
    $template = new AvatarTemplate;
    $template->forceFill([
        'config' => [
            'avatarId' => 'av-1',
            'voiceId' => 'voice-1',
            'language' => 'it',
            // Unproven — from avatar-tester, no demonstrated-working call.
            'voiceSpeed' => 1.2,
            'maxSessionDurationSec' => 600,
            'videoEncoding' => 'h264',
        ],
    ]);

    app()->instance(
        ActiveTemplateResolver::class,
        new class($template)
        {
            public function __construct(private readonly AvatarTemplate $template) {}

            public function resolve(): AvatarTemplate
            {
                return $this->template;
            }
        },
    );

    $capturedTokenBody = [];
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-allowlist']], 200),
        '*liveavatar*/sessions/token*' => function ($request) use (&$capturedTokenBody) {
            $capturedTokenBody = $request->data();

            return Http::response(['data' => ['session_id' => 'sid-allowlist', 'session_token' => 'tok-allowlist']], 200);
        },
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;
    $provider->issue($session, $ctx);

    // Demo-proven fields present
    expect($capturedTokenBody)->toHaveKey('avatar_id', 'av-1');
    expect($capturedTokenBody)->toHaveKey('avatar_persona.voice_id', 'voice-1');

    // Unproven fields dropped by default — config('interview.heygen.extra_token_fields') is []
    expect($capturedTokenBody)->not->toHaveKey('voice_settings');
    expect($capturedTokenBody)->not->toHaveKey('max_session_duration');
    expect($capturedTokenBody)->not->toHaveKey('video_settings.encoding');

    app()->forgetInstance(ActiveTemplateResolver::class);
});

test('HeygenProvider::issue() sends avatar_id from platform config when the org has NO active AvatarTemplate (hotfix 0.22.1 regression — production 422 "avatar_id: Field required")', function (): void {
    // No ActiveTemplateResolver override bound — exercises the REAL resolver
    // against an empty avatar_templates table, i.e. the exact production
    // condition every org is in today: no active template.
    config([
        'interview.heygen.avatar_id' => 'platform-default-avatar',
        'interview.heygen.voice_id' => 'platform-default-voice',
        'interview.heygen.language' => 'en',
    ]);

    $capturedTokenBody = [];
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-platform-default']], 200),
        '*liveavatar*/sessions/token*' => function ($request) use (&$capturedTokenBody) {
            $capturedTokenBody = $request->data();

            return Http::response([
                'data' => ['session_id' => 'sid-platform-default', 'session_token' => 'tok-platform-default'],
            ], 200);
        },
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;
    $provider->issue($session, $ctx);

    expect($capturedTokenBody)->toHaveKey('avatar_id', 'platform-default-avatar');
    expect($capturedTokenBody)->toHaveKey('avatar_persona.voice_id', 'platform-default-voice');
    expect($capturedTokenBody)->toHaveKey('avatar_persona.language', 'en');

    // The two proven-constant fields (@wire-source start.ts:216-220) are ALSO
    // always sent, independent of any template — this is the second half of
    // the same production defect (D2's "unverified, default OFF" classification
    // was wrong for these two: they are in the demonstrated-working call).
    expect($capturedTokenBody)->toHaveKey('interactivity_type', 'CONVERSATIONAL');
    expect($capturedTokenBody)->toHaveKey('video_settings.quality', 'low');
});

test('HeygenProvider::issue() sources avatar_persona.language from QuestionContext.language (project language), falling back to platform config default', function (): void {
    config(['interview.heygen.language' => 'it']);

    $capturedTokenBody = [];
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-lang']], 200),
        '*liveavatar*/sessions/token*' => function ($request) use (&$capturedTokenBody) {
            $capturedTokenBody = $request->data();

            return Http::response(['data' => ['session_id' => 'sid-lang', 'session_token' => 'tok-lang']], 200);
        },
    ]);

    $session = mockSession('heygen');
    $provider = new HeygenProvider;

    // Explicit language on QuestionContext (threaded from $project->language by
    // the controller) WINS over the platform config default.
    $ctxWithLanguage = new QuestionContext(competencyCode: 'PRS', questionIndex: 0, language: 'en');
    $provider->issue($session, $ctxWithLanguage);
    expect($capturedTokenBody)->toHaveKey('avatar_persona.language', 'en');

    // No language on QuestionContext (e.g. ProviderSmokeCheck's standalone fake
    // session) → falls back to the platform config default.
    $ctxWithoutLanguage = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider->issue($session, $ctxWithoutLanguage);
    expect($capturedTokenBody)->toHaveKey('avatar_persona.language', 'it');
});

test('HeygenProvider::issue() lets an org\'s active AvatarTemplate override the platform-default avatar_id (precedence: template wins)', function (): void {
    config(['interview.heygen.avatar_id' => 'platform-default-avatar']);

    $template = new AvatarTemplate;
    $template->forceFill(['config' => ['avatarId' => 'org-template-avatar']]);

    app()->instance(
        ActiveTemplateResolver::class,
        new class($template)
        {
            public function __construct(private readonly AvatarTemplate $template) {}

            public function resolve(): AvatarTemplate
            {
                return $this->template;
            }
        },
    );

    $capturedTokenBody = [];
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-precedence']], 200),
        '*liveavatar*/sessions/token*' => function ($request) use (&$capturedTokenBody) {
            $capturedTokenBody = $request->data();

            return Http::response(['data' => ['session_id' => 'sid-precedence', 'session_token' => 'tok-precedence']], 200);
        },
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;
    $provider->issue($session, $ctx);

    expect($capturedTokenBody)->toHaveKey('avatar_id', 'org-template-avatar');

    app()->forgetInstance(ActiveTemplateResolver::class);
});

test('HeygenProvider::issue() reads data.session_token, NOT data.access_token (hotfix 0.22.1 — the second latent bug)', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-session-token']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => ['session_id' => 'sid-session-token', 'session_token' => 'the-real-session-token'],
        ], 200),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;
    $token = $provider->issue($session, $ctx);

    expect($token->token)->toBe('the-real-session-token');
});

test('HeygenProvider::issue() tolerates a null session_id — provider_session_ref is nullable end-to-end, not a malformed-response error', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-null-session-id']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            // session_id omitted entirely, matching legacy-demo's `data.session_id ?? null`.
            'data' => ['session_token' => 'tok-null-session-id'],
        ], 200),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;
    $token = $provider->issue($session, $ctx);

    expect($token->token)->toBe('tok-null-session-id');
    expect($token->provider_session_ref)->toBeNull();
});

test('HeygenProvider::issue() throws a malformed-response ProviderException when session_token is missing', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['id' => 'ctx-missing-token']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => ['session_id' => 'sid-missing-token'],
        ], 200),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;

    expect(fn () => $provider->issue($session, $ctx))
        ->toThrow(ProviderException::class, 'HeyGen: malformed token response (missing session_token)');
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

test('HeygenProvider::issue() on a client-side 4xx classifies as ClientError, not retryable (PR1 D4)', function (): void {
    // 422: LiveAvatar rejected OUR request body — a client contract error, not an
    // upstream failure. This did NOT exist as a distinct class before PR1: prior
    // code folded every non-429 status (4xx and 5xx alike) into "not retryable",
    // which the controller then treated identically to a genuine 5xx (502 + participant
    // errore). This test pins the new three-way split at the provider layer.
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'prompt is required'], 422),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;

    try {
        $provider->issue($session, $ctx);
        $this->fail('Expected ProviderException to be thrown');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe(ProviderFailureClass::ClientError);
        expect($e->isRetryable())->toBeFalse();
    }
});

test('HeygenProvider::issue() on 5xx still classifies as Upstream (PR1 D4 — unchanged)', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'Internal Server Error'], 503),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;

    try {
        $provider->issue($session, $ctx);
        $this->fail('Expected ProviderException to be thrown');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe(ProviderFailureClass::Upstream);
        expect($e->isRetryable())->toBeFalse();
    }
});

test('HeygenProvider::issue() on 429 still classifies as Throttle (PR1 D4 — unchanged)', function (): void {
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['error' => 'Too Many Requests'], 429),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;

    try {
        $provider->issue($session, $ctx);
        $this->fail('Expected ProviderException to be thrown');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe(ProviderFailureClass::Throttle);
        expect($e->isRetryable())->toBeTrue();
    }
});

test('HeygenProvider::issue() on a 4xx preserves the provider message AND redacts the key — one test, both halves (PR1 D6)', function (): void {
    // The message contains BOTH a genuine diagnostic AND the API key (the worst case:
    // the provider echoed it back). Redaction must not throw away the diagnostic to
    // achieve safety — it must keep one and drop the other.
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(
            ['message' => 'prompt is required (caller key: SUPER_SECRET_HEYGEN_KEY_12345)'],
            422
        ),
    ]);

    $session = mockSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new HeygenProvider;

    try {
        $provider->issue($session, $ctx);
        $this->fail('Expected ProviderException to be thrown');
    } catch (ProviderException $e) {
        expect($e->getMessage())->toContain('prompt is required');
        expect($e->getMessage())->not->toContain('SUPER_SECRET_HEYGEN_KEY_12345');
    }
});

test('HeygenProvider::reconcileTranscript() returns array', function (): void {
    Http::fake([
        // @wire-source legacy-demo/src/pages/api/interview/end.ts:76-84 — real shape
        // is `data.transcript_data`, rows keyed `role`/`transcript` (PR4 — not the
        // invented `data`/`role`/`content` shape this test previously pinned).
        '*liveavatar*/sessions/*/transcript*' => Http::response([
            'data' => [
                'transcript_data' => [
                    ['role' => 'user',      'transcript' => 'Hello', 'time_ms' => 1000],
                    ['role' => 'assistant', 'transcript' => 'Hi',    'time_ms' => 2000],
                ],
            ],
        ], 200),
    ]);

    $session = mockSession('heygen', 'ref-session-123');
    $provider = new HeygenProvider;
    $result = $provider->reconcileTranscript($session);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
});

test('HeygenProvider::reconcileTranscript() maps role → speaker: user/candidate → candidate, assistant/avatar/agent → avatar', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response([
            'data' => [
                'transcript_data' => [
                    ['role' => 'user',      'transcript' => 'A', 'time_ms' => 1000],
                    ['role' => 'candidate', 'transcript' => 'B', 'time_ms' => 2000],
                    ['role' => 'assistant', 'transcript' => 'C', 'time_ms' => 3000],
                    ['role' => 'avatar',    'transcript' => 'D', 'time_ms' => 4000],
                    ['role' => 'agent',     'transcript' => 'E', 'time_ms' => 5000],
                ],
            ],
        ], 200),
    ]);

    $session = mockSession('heygen', 'ref-session-roles');
    $provider = new HeygenProvider;
    $result = $provider->reconcileTranscript($session);

    expect($result[0]['speaker'])->toBe('candidate');
    expect($result[1]['speaker'])->toBe('candidate');
    expect($result[2]['speaker'])->toBe('avatar');
    expect($result[3]['speaker'])->toBe('avatar');
    expect($result[4]['speaker'])->toBe('avatar');
    expect($result[0]['text'])->toBe('A');
});

test('HeygenProvider::reconcileTranscript() with a genuinely empty transcript_data returns []', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => ['transcript_data' => []]], 200),
    ]);

    $session = mockSession('heygen', 'ref-session-empty');
    $provider = new HeygenProvider;
    $result = $provider->reconcileTranscript($session);

    expect($result)->toBe([]);
});

test('HeygenProvider::reconcileTranscript() throws ProviderTranscriptShapeException when data.transcript_data is absent', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => []], 200),
    ]);

    $session = mockSession('heygen', 'ref-session-drifted');
    $provider = new HeygenProvider;

    expect(fn () => $provider->reconcileTranscript($session))
        ->toThrow(ProviderTranscriptShapeException::class);
});

test('HeygenProvider::reconcileTranscript() throws ProviderTranscriptShapeException when a row is missing role or transcript', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response([
            'data' => ['transcript_data' => [['role' => 'user']]],
        ], 200),
    ]);

    $session = mockSession('heygen', 'ref-session-malformed-row');
    $provider = new HeygenProvider;

    expect(fn () => $provider->reconcileTranscript($session))
        ->toThrow(ProviderTranscriptShapeException::class);
});

test('HeygenProvider::reconcileTranscript() throws ProviderTranscriptShapeException on an unknown role', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response([
            'data' => ['transcript_data' => [['role' => 'system', 'transcript' => 'Unexpected role']]],
        ], 200),
    ]);

    $session = mockSession('heygen', 'ref-session-unknown-role');
    $provider = new HeygenProvider;

    expect(fn () => $provider->reconcileTranscript($session))
        ->toThrow(ProviderTranscriptShapeException::class);
});

test('HeygenProvider::reconcileTranscript() with time_ms absent falls back to now() (@wire-source none — inferred)', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response([
            'data' => ['transcript_data' => [['role' => 'user', 'transcript' => 'No timestamp']]],
        ], 200),
    ]);

    $session = mockSession('heygen', 'ref-session-no-time');
    $provider = new HeygenProvider;
    $result = $provider->reconcileTranscript($session);

    expect($result)->toHaveCount(1);
    expect($result[0]['ts'])->toBeString();
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
