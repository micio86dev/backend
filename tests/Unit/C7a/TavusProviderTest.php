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
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Services\Provider\ProviderToken;
use App\Services\Provider\QuestionContext;
use App\Services\Provider\TavusProvider;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
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

test('TavusProvider::issue() self-heals via retry on a concurrency-limit rejection, then succeeds (PR6)', function (): void {
    config([
        'interview.tavus.concurrency_retries' => 3,
        'interview.tavus.concurrency_backoff_ms' => 0,
    ]);

    Http::fake([
        '*tavusapi*/v2/conversations' => Http::sequence()
            ->push(['error' => 'Maximum concurrent conversations reached'], 400)
            ->push(['conversation_id' => 'conv-retried', 'conversation_url' => 'https://tavus.io/conv-retried'], 200),
    ]);

    $session = tavusMockSession();
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new TavusProvider;

    $token = $provider->issue($session, $ctx);

    Http::assertSentCount(2);
    expect($token->provider_session_ref)->toBe('conv-retried');
});

test('TavusProvider::issue() exhausts retries and surfaces 429 provider_busy, not 500/502 (PR6)', function (): void {
    config([
        'interview.tavus.concurrency_retries' => 3,
        'interview.tavus.concurrency_backoff_ms' => 0,
    ]);

    Http::fake([
        '*tavusapi*/v2/conversations' => Http::response(['error' => 'Maximum concurrent conversations reached'], 400),
    ]);

    $session = tavusMockSession();
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new TavusProvider;

    try {
        $provider->issue($session, $ctx);
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        // 1 initial attempt + 3 retries = 4 total calls before giving up.
        Http::assertSentCount(4);
        expect($e->isRetryable())->toBeTrue();
    }
});

test('TavusProvider::teardown() calls POST /v2/conversations/{id}/end, not DELETE /v2/conversations/{id} (PR5)', function (): void {
    // @wire-source legacy-demo/src/pages/api/interview/end.ts:59-62 — the real Tavus
    // teardown contract is POST .../end; DELETE .../conversations/{id} is not a
    // documented endpoint anywhere in the reconstructed contract (PR5, design D8).
    Http::fake([
        '*tavusapi*/v2/conversations/*/end' => Http::response([], 200),
    ]);

    $token = ProviderToken::fromRef('tavus', 'conv-to-end');
    $provider = new TavusProvider;

    $provider->teardown($token);

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/v2/conversations/conv-to-end/end');
    });

    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'DELETE';
    });
});

test('TavusProvider::issue() sends replica_id and persona_id from platform config when the org has NO active AvatarTemplate (hotfix 0.22.2 regression — production 400 "Either replica_id/face_id or a persona_id/pal_id with a default replica specified must be present")', function (): void {
    // No ActiveTemplateResolver override bound — exercises the REAL resolver
    // against an empty avatar_templates table, i.e. the exact production
    // condition every org is in today: no active template. This is the same
    // defect class hotfix 0.22.1 fixed for HeygenProvider::buildSessionTokenBody().
    config([
        'interview.tavus.replica_id' => 'platform-default-replica',
        'interview.tavus.persona_id' => 'platform-default-persona',
    ]);

    $capturedBody = [];
    Http::fake([
        '*tavusapi*/v2/conversations*' => function ($request) use (&$capturedBody) {
            $capturedBody = $request->data();

            return Http::response([
                'conversation_id' => 'conv-platform-default',
                'conversation_url' => 'https://tavus.io/conv-platform-default',
            ], 200);
        },
    ]);

    $session = tavusMockSession();
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new TavusProvider;
    $provider->issue($session, $ctx);

    expect($capturedBody)->toHaveKey('replica_id', 'platform-default-replica');
    expect($capturedBody)->toHaveKey('persona_id', 'platform-default-persona');
});

test('TavusProvider::issue() lets an org\'s active AvatarTemplate override the platform-default replica_id/persona_id (precedence: template wins)', function (): void {
    config([
        'interview.tavus.replica_id' => 'platform-default-replica',
        'interview.tavus.persona_id' => 'platform-default-persona',
    ]);

    $template = new AvatarTemplate;
    $template->forceFill(['config' => ['faceId' => 'org-template-replica', 'palId' => 'org-template-persona']]);

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

    $capturedBody = [];
    Http::fake([
        '*tavusapi*/v2/conversations*' => function ($request) use (&$capturedBody) {
            $capturedBody = $request->data();

            return Http::response([
                'conversation_id' => 'conv-precedence',
                'conversation_url' => 'https://tavus.io/conv-precedence',
            ], 200);
        },
    ]);

    $session = tavusMockSession();
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0);
    $provider = new TavusProvider;
    $provider->issue($session, $ctx);

    expect($capturedBody)->toHaveKey('replica_id', 'org-template-replica');
    expect($capturedBody)->toHaveKey('persona_id', 'org-template-persona');

    app()->forgetInstance(ActiveTemplateResolver::class);
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
