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

use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Services\ConversationLlm\LlmBindingResolver;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\QuestionContext;
use App\Services\Provider\TavusProvider;
use App\Support\AvatarTemplates\TavusPalSync;
use App\Support\Tenancy\TenantContextScope;
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

// ─── Helpers — PR P4 Tavus PAL binding merge ──────────────────────────────────

function palGeminiModel(): LlmModel
{
    return LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
}

function palGeminiCredentialForOrg(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $orgId,
            'name' => 'Pal-cred-'.uniqid(),
            'vendor' => 'google',
            'api_key' => 'sk-real-gemini-key',
            'key_last_four' => 'lkey',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

/**
 * @param  array<string, mixed>  $config
 */
function palBoundTemplate(array $config, ?LlmModel $model = null, ?LlmCredential $credential = null): AvatarTemplate
{
    $org = Organization::factory()->create();
    $model ??= palGeminiModel();
    $credential ??= palGeminiCredentialForOrg($org->id);

    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'PAL bound '.uniqid(),
        'provider' => 'tavus',
        'config' => $config,
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
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
    // template payload). Hotfix 0.22.2: `replica_id`/`persona_id` are NOT
    // "legitimately absent" in that case — their absence was the production
    // 400 ("Either replica_id/face_id or a persona_id/pal_id with a default
    // replica specified must be present"). They now come from the
    // platform-default config floor (`interview.tavus.{replica_id,
    // persona_id}`, `TavusProvider::platformDefaultConversationFields()`) and
    // are ALWAYS present in the golden body.
    //
    // `properties.language` joined them (avatar-language-follows-project, D2/D4)
    // for the same reason and by the same route: the avatar's spoken language
    // follows the PROJECT, so it can no longer come from a template, and the
    // platform default supplies it when — as here — the caller passes no
    // QuestionContext language. It is written NESTED because that is where the
    // demonstrated-working demo call puts it; Tavus ignores a field at the wrong
    // path in silence. Every OTHER `properties.*` key remains absent: those are
    // template-only knobs the provider does not require.
    $this->assertEqualsCanonicalizing($golden, $capturedBody);
});

// ─── PR P4 — Tavus PAL binding merge (design D7) ──────────────────────────────
//
// These three tests pin `TavusPalSync::sync()`'s PAL PATCH body — NOT the
// `/v2/conversations` create body covered above. `layers.llm.{model,base_url,
// api_key}` is the managed-mode binding (design D6/D7); `layers.llm.extra_body.
// temperature` is the pre-existing, unrelated `llmTemperature` persona knob.
// Both write inside the SAME `llm` node, which is exactly why `array_merge`
// would silently drop one side (D7's rationale for `array_replace_recursive`).
//
// @wire-source live Tavus API smoke-check, 2026-08-26 (Phase 0.3(c)): Tavus
// does NOT retain a previously-submitted `layers.llm.api_key` across PATCHes
// — `PATCH /v2/pals/{id}` omitting `api_key` returns HTTP 400 ("Please ensure
// both base_url and api_key are included in order to use a custom llm."),
// never a silent no-op. Re-submitting the key on every PATCH is therefore
// MANDATORY, and this is why the merge below feeds `layers.llm.api_key` from
// the resolved binding on every single sync call, not once at bind time.

test('L2 (P4): a Tavus PAL PATCH carries both the binding and the pre-existing temperature knob merged, never one replacing the other', function (): void {
    config()->set('interview.tavus.api_key', 'platform-tavus-key');

    $capturedBody = [];
    Http::fake(function ($request) use (&$capturedBody) {
        $capturedBody = $request->data();

        return Http::response([], 200);
    });

    $model = palGeminiModel();
    $template = palBoundTemplate([
        'faceId' => 'r', 'palId' => 'p_bound',
        'llmTemperature' => 0.5,
    ], $model);

    $result = app(TavusPalSync::class)->sync($template);

    expect($result['status'])->toBe('synced');

    $binding = app(LlmBindingResolver::class)->resolve($template);

    $golden = loadProviderFixture('tavus/pal_patch_layers_bound_golden.json');
    $golden['llm']['model'] = $model->key;
    $golden['llm']['base_url'] = $model->base_url;
    $golden['llm']['api_key'] = $binding->apiKey;

    // The non-negotiable proof: BOTH nodes are present in the SAME request —
    // a shallow array_merge would have replaced the whole `llm` key with
    // whichever side was applied last and silently dropped the other.
    $this->assertEqualsCanonicalizing($golden, $capturedBody[0]['value']);
});

test('L2 (P4): a bound template with an otherwise-empty persona config is NOT skipped by the empty-layers guard', function (): void {
    config()->set('interview.tavus.api_key', 'platform-tavus-key');

    // `tavusPalLayers()` on this config alone produces `[]` — no faceId/voiceId/
    // llmTemperature/etc. maps to a `palPath`. Before the guard moved AFTER the
    // merge (design D7), this template would have been skipped and its binding
    // never reached the PAL — a CERTAINTY, not a risk, per D7's rationale.
    $template = palBoundTemplate(['palId' => 'p_empty_config']);

    Http::fake(['*' => Http::response([], 200)]);

    $result = app(TavusPalSync::class)->sync($template);

    expect($result['status'])->toBe('synced');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/pals/p_empty_config')
        && array_key_exists('llm', $request->data()[0]['value']));
});

test('L2 (P4, regression): an unbound template\'s PAL PATCH body is byte-identical to develop', function (): void {
    config()->set('interview.tavus.api_key', 'platform-tavus-key');

    $capturedBody = [];
    Http::fake(function ($request) use (&$capturedBody) {
        $capturedBody = $request->data();

        return Http::response([], 200);
    });

    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'PAL unbound '.uniqid(),
        'provider' => 'tavus',
        'config' => ['faceId' => 'r', 'palId' => 'p_unbound', 'llmTemperature' => 0.5],
    ]));

    $result = app(TavusPalSync::class)->sync($template);

    expect($result['status'])->toBe('synced');

    // No `llm.model`/`llm.base_url`/`llm.api_key` key anywhere — the merge is a
    // strict no-op for a template that never binds. This is the byte-identical
    // regression proof: the outbound layers node is EXACTLY the pre-P4 shape.
    $layers = $capturedBody[0]['value'];
    expect($layers)->toBe(['llm' => ['extra_body' => ['temperature' => 0.5]]]);
});

// ─── PR P5 — HeyGen secret/configuration lifecycle (design D8) ────────────────
//
// L1: `HeygenLlmRegistrar` correctly reads `data.id` from BOTH
// `/v1/secrets` and `/v1/llm-configurations` responses — @wire-source live
// HeyGen API smoke-check, 2026-08-26: the id is at `data.id`, NOT top level,
// on both endpoints.
//
// L2: the outbound `/v1/secrets` and `/v1/llm-configurations` POST bodies
// match a golden shape — proves "unchanged", not "correct", same L2 doctrine
// as the Tavus/LiveAvatar goldens above. Deliberately does NOT cover the
// `/v1/sessions/token` body's `llm_configuration_id` placement — design D8's
// placement inside `$providerOwned` is this batch's BEST GUESS (see
// `HeygenProvider::buildSessionTokenBody()`'s docblock), UNVERIFIED by any
// live conversational test, and pinning it in a golden fixture would encode
// a guess as a fact.

test('L1: HeygenLlmRegistrar::ensureSecret() correctly reads data.id from a docs-verified /v1/secrets response', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    $fixture = loadProviderFixture('heygen/secret_create_response.json');

    Http::fake(['*liveavatar.com/v1/secrets' => Http::response($fixture, 200)]);

    $org = Organization::factory()->create();
    $credential = palGeminiCredentialForOrg($org->id);

    $secretId = app(HeygenLlmRegistrar::class)->ensureSecret($credential);

    expect($secretId)->toBe($fixture['data']['id']);
    expect($credential->fresh()->heygen_secret_id)->toBe($fixture['data']['id']);
});

test('L1: HeygenLlmRegistrar::ensureConfiguration() correctly reads data.id from a docs-verified /v1/llm-configurations response', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    $secretFixture = loadProviderFixture('heygen/secret_create_response.json');
    $configFixture = loadProviderFixture('heygen/llm_configuration_create_response.json');

    Http::fake([
        '*liveavatar.com/v1/secrets' => Http::response($secretFixture, 200),
        '*liveavatar.com/v1/llm-configurations' => Http::response($configFixture, 200),
    ]);

    $model = palGeminiModel();
    $template = palBoundTemplate(['avatarId' => 'a', 'voiceId' => 'v'], $model);
    $template->forceFill(['provider' => 'heygen'])->saveQuietly();

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration($template->fresh());

    expect($result['status'])->toBe('synced');
    expect($template->fresh()->heygen_llm_configuration_id)->toBe($configFixture['data']['id']);
});

test('L2: HeyGen /v1/secrets outbound body matches the golden JSON', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        $capturedBody = $request->data();

        return Http::response(loadProviderFixture('heygen/secret_create_response.json'), 200);
    });

    $org = Organization::factory()->create();
    $credential = palGeminiCredentialForOrg($org->id);

    app(HeygenLlmRegistrar::class)->ensureSecret($credential);

    $golden = loadProviderFixture('heygen/secret_create_request_golden.json');
    $golden['secret_value'] = $credential->api_key;
    $golden['secret_name'] = $capturedBody['secret_name'];

    expect($capturedBody['secret_name'])->toBe("beai-org{$org->id}-cred{$credential->id}");
    $this->assertEqualsCanonicalizing($golden, $capturedBody);
});

test('L2: HeyGen /v1/llm-configurations outbound body matches the golden JSON', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    $capturedBody = [];

    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/v1/secrets')) {
            return Http::response(loadProviderFixture('heygen/secret_create_response.json'), 200);
        }

        $capturedBody = $request->data();

        return Http::response(loadProviderFixture('heygen/llm_configuration_create_response.json'), 200);
    });

    $model = palGeminiModel();
    $template = palBoundTemplate(['avatarId' => 'a', 'voiceId' => 'v'], $model);
    $template->forceFill(['provider' => 'heygen'])->saveQuietly();

    app(HeygenLlmRegistrar::class)->ensureConfiguration($template->fresh());

    $golden = loadProviderFixture('heygen/llm_configuration_create_request_golden.json');
    $golden['display_name'] = $capturedBody['display_name'];
    $golden['model_name'] = $model->key;
    $golden['base_url'] = $model->base_url;
    $golden['secret_id'] = loadProviderFixture('heygen/secret_create_response.json')['data']['id'];

    $this->assertEqualsCanonicalizing($golden, $capturedBody);
});

// ─── PR P5 — HeyGen session-token binding (design D8) ─────────────────────────

test('L2 (P5): a bound HeyGen template carries llm_configuration_id in the session-token body, at the top level', function (): void {
    $model = palGeminiModel();
    $template = palBoundTemplate(['avatarId' => 'a', 'voiceId' => 'v'], $model);
    $template->forceFill([
        'provider' => 'heygen',
        'is_active' => true,
        'heygen_llm_configuration_id' => 'cfg_bound_123',
    ])->saveQuietly();

    $capturedBody = [];
    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-p5']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            $capturedBody = $request->data();

            return Http::response(['data' => ['session_id' => 'sid-p5', 'session_token' => 'tok-p5']], 200);
        }

        return Http::response([], 200);
    });

    $session = fixtureContractSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0, systemPrompt: 'p', promptVersion: 'v1');

    TenantContextScope::runFor($template->organization_id, fn () => (new HeygenProvider)->issue($session, $ctx));

    // A SHAPE assertion only — "the field is present when bound" — never a
    // placement CORRECTNESS claim. The Phase 0.3(a) control experiment
    // proved a 200 from this endpoint cannot discriminate where the field
    // belongs (see `HeygenProvider.php`'s docblock); only a live
    // conversational test could confirm it, and none has run.
    expect($capturedBody)->toHaveKey('llm_configuration_id');
    expect($capturedBody['llm_configuration_id'])->toBe('cfg_bound_123');
});

test('L2 (P5): the token-field allowlist environment variable cannot remove llm_configuration_id from the body', function (): void {
    // Design D8's rationale: TOKEN_FIELD_ALLOWLIST is union'd with
    // `interview.heygen.extra_token_fields`, an ENV VAR — routing the
    // binding through it would let an environment change silently disable
    // every tenant's LLM binding with no deploy and no diff. Placing it in
    // `$providerOwned` instead means this config change cannot touch it
    // at all — proven here by mutating the allowlist-governing config
    // itself and asserting the field survives regardless.
    config()->set('interview.heygen.extra_token_fields', ['nonexistent.field.path']);

    $model = palGeminiModel();
    $template = palBoundTemplate(['avatarId' => 'a', 'voiceId' => 'v'], $model);
    $template->forceFill([
        'provider' => 'heygen',
        'is_active' => true,
        'heygen_llm_configuration_id' => 'cfg_allowlist_proof',
    ])->saveQuietly();

    $capturedBody = [];
    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-allowlist']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            $capturedBody = $request->data();

            return Http::response(['data' => ['session_id' => 'sid-allowlist', 'session_token' => 'tok-allowlist']], 200);
        }

        return Http::response([], 200);
    });

    $session = fixtureContractSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0, systemPrompt: 'p', promptVersion: 'v1');

    TenantContextScope::runFor($template->organization_id, fn () => (new HeygenProvider)->issue($session, $ctx));

    expect($capturedBody['llm_configuration_id'])->toBe('cfg_allowlist_proof');
});

test('L2 (P5, regression): an unbound HeyGen template carries no llm_configuration_id at all', function (): void {
    $capturedBody = [];
    Http::fake(function ($request) use (&$capturedBody) {
        if (str_contains($request->url(), '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-p5-unbound']], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            $capturedBody = $request->data();

            return Http::response(['data' => ['session_id' => 'sid-p5-u', 'session_token' => 'tok-p5-u']], 200);
        }

        return Http::response([], 200);
    });

    $session = fixtureContractSession('heygen');
    $ctx = new QuestionContext(competencyCode: 'PRS', questionIndex: 0, systemPrompt: 'p', promptVersion: 'v1');

    (new HeygenProvider)->issue($session, $ctx);

    expect($capturedBody)->not->toHaveKey('llm_configuration_id');
});
