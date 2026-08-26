<?php

declare(strict_types=1);

/**
 * `HeygenLlmRegistrar`'s four-verb lifecycle (pluggable-conversation-llm PR
 * P5, design D8).
 *
 * Mirrors `TavusPalSyncTest.php`'s shape: `Http::fake()` + `Http::assertSent()`
 * on the exact outbound request, never a captured live recording.
 *
 * @wire-source live HeyGen API smoke-check, 2026-08-26. `POST /v1/secrets` →
 * HTTP 200, `{code, data:{id, secret_name}, message}` — the id is at
 * `data.id`. `secret_name` is NOT unique: two identical-name POSTs return
 * DIFFERENT ids. `PATCH`/`PUT /v1/secrets/{id}` → 405 (immutable). `POST
 * /v1/llm-configurations` echoes `{id, base_url, display_name, model_name,
 * secret_id}` under `data`.
 */

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function registrarModel(): LlmModel
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

function registrarCredentialForOrg(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $orgId,
            'name' => 'Registrar-cred-'.uniqid(),
            'vendor' => 'google',
            'api_key' => 'sk-real-gemini-key',
            'key_last_four' => 'lkey',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

function registrarHeygenTemplate(?LlmModel $model = null, ?LlmCredential $credential = null): AvatarTemplate
{
    $org = Organization::factory()->create();
    $model ??= registrarModel();
    $credential ??= registrarCredentialForOrg($org->id);

    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Registrar template '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
}

function heygenSecretResponse(string $id = 'sec_1'): array
{
    return ['code' => 1000, 'data' => ['id' => $id, 'secret_name' => 'x'], 'message' => 'success'];
}

function heygenConfigurationResponse(string $id = 'cfg_1'): array
{
    return ['data' => [
        'id' => $id,
        'display_name' => 'x',
        'model_name' => 'gemini-3-flash-preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'secret_id' => 'sec_1',
    ]];
}

beforeEach(function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
});

// ─── Return shape / never-throws contract ─────────────────────────────────────

test('ensureConfiguration() returns the exact shape TavusPalSync declares, and never throws on a provider 500', function (): void {
    Http::fake(['*heygen.com*' => Http::response(['error' => 'boom'], 500)]);

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration(registrarHeygenTemplate());

    expect($result)->toHaveKey('status');
    expect($result['status'])->toBe('warning');
    expect($result['message'])->toBeString();
});

test('ensureConfiguration() never throws when the provider is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration(registrarHeygenTemplate());

    expect($result['status'])->toBe('warning');
});

test('a non-heygen template is skipped without any HTTP call', function (): void {
    Http::fake();

    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Tavus template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration($template);

    expect($result['status'])->toBe('skipped');
    Http::assertNothingSent();
});

// ─── Create: first bind registers a secret then a configuration ──────────────

test('create: first bind POSTs /v1/secrets then POSTs /v1/llm-configurations, and both ids are stored', function (): void {
    Http::fake([
        '*heygen.com/v1/secrets' => Http::response(heygenSecretResponse('sec_new'), 200),
        '*heygen.com/v1/llm-configurations' => Http::response(heygenConfigurationResponse('cfg_new'), 200),
    ]);

    $template = registrarHeygenTemplate();

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration($template);

    expect($result['status'])->toBe('synced');
    expect($template->fresh()->heygen_llm_configuration_id)->toBe('cfg_new');
    // Loaded unscoped, not via the `llmCredential` relation — the relation
    // applies TenantScoped's global scope, which the CURRENT process may not
    // be inside at this point in the test.
    expect(LlmCredential::withoutGlobalScopes()->find($template->llm_credential_id)->heygen_secret_id)->toBe('sec_new');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/secrets')) {
            return true;
        }

        return $request->method() === 'POST'
            && $request['secret_type'] === 'OPENAI_API_KEY'
            && $request['secret_value'] === 'sk-real-gemini-key'
            && is_string($request['secret_name']);
    });

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/llm-configurations')) {
            return true;
        }

        return $request->method() === 'POST'
            && $request['model_name'] === 'gemini-3-flash-preview'
            && $request['base_url'] === 'https://generativelanguage.googleapis.com/v1beta/openai/'
            && $request['secret_id'] === 'sec_new';
    });
});

test('the secret id is memoized — a second ensureConfiguration() on a NEW template sharing the credential issues no second POST /v1/secrets', function (): void {
    Http::fake([
        '*heygen.com/v1/secrets' => Http::response(heygenSecretResponse('sec_shared'), 200),
        '*heygen.com/v1/llm-configurations' => Http::response(heygenConfigurationResponse('cfg_a'), 200),
    ]);

    $model = registrarModel();
    $org = Organization::factory()->create();
    $credential = registrarCredentialForOrg($org->id);

    $templateA = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Shared A', 'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
    ]));
    $templateB = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Shared B', 'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
    ]));

    app(HeygenLlmRegistrar::class)->ensureConfiguration($templateA);

    // secret_name is NOT unique on the vendor side (Phase 0.3 live evidence)
    // — a second POST here would silently create an ORPHAN secret rather
    // than failing loudly, which is exactly why memoization matters.
    app(HeygenLlmRegistrar::class)->ensureConfiguration($templateB);

    $secretPosts = collect(Http::recorded())->filter(
        fn (array $pair): bool => str_contains($pair[0]->url(), '/v1/secrets') && $pair[0]->method() === 'POST'
    );

    expect($secretPosts)->toHaveCount(1);
    expect($credential->fresh()->heygen_secret_id)->toBe('sec_shared');
});

// ─── Update: a model change PATCHes the existing configuration ───────────────

test('update: a model change on a bound template PATCHes the stored configuration id, never a new POST', function (): void {
    Http::fake([
        '*heygen.com/v1/secrets' => Http::response(heygenSecretResponse('sec_1'), 200),
        '*heygen.com/v1/llm-configurations' => Http::response(heygenConfigurationResponse('cfg_1'), 200),
    ]);

    $template = registrarHeygenTemplate();
    app(HeygenLlmRegistrar::class)->ensureConfiguration($template);
    expect($template->fresh()->heygen_llm_configuration_id)->toBe('cfg_1');

    // A fresh `Http::fake()` call RESETS `Http::recorded()`
    // (`Illuminate\Http\Client\Factory::fake()` sets `$this->recorded = []`
    // on every call, though registered URL patterns themselves STACK) —
    // exactly what isolates the assertions below to this second phase alone.
    Http::fake([
        '*heygen.com/v1/llm-configurations/cfg_1' => Http::response(heygenConfigurationResponse('cfg_1'), 200),
    ]);

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration($template->fresh());

    expect($result['status'])->toBe('synced');
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/v1/llm-configurations/cfg_1'));
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/v1/llm-configurations'));
});

test('a 404 on PATCH clears the stored id and retries exactly once as a POST', function (): void {
    Http::fake(['*heygen.com/v1/secrets' => Http::response(heygenSecretResponse('sec_1'), 200)]);

    // The bare `/v1/llm-configurations` URL is hit TWICE in this test (the
    // initial create, then the post-404 retry) — a plain `Http::fake()`
    // re-registration for the SAME pattern would leave the FIRST response
    // matching forever (the documented stacking gotcha), so a sequence is
    // the correct tool, exactly as `TavusSyncStatePersistenceTest.php` uses
    // for the analogous Tavus case.
    Http::fakeSequence('*heygen.com/v1/llm-configurations')
        ->push(heygenConfigurationResponse('cfg_1'), 200)
        ->push(heygenConfigurationResponse('cfg_recreated'), 200);

    // A distinct sub-path pattern — never consumes the sequence above.
    Http::fake(['*heygen.com/v1/llm-configurations/cfg_1' => Http::response([], 404)]);

    $template = registrarHeygenTemplate();
    app(HeygenLlmRegistrar::class)->ensureConfiguration($template);
    expect($template->fresh()->heygen_llm_configuration_id)->toBe('cfg_1');

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration($template->fresh());

    expect($result['status'])->toBe('synced');
    expect($template->fresh()->heygen_llm_configuration_id)->toBe('cfg_recreated');

    $configCalls = collect(Http::recorded())->filter(
        fn (array $pair): bool => str_contains($pair[0]->url(), '/v1/llm-configurations')
    )->values();

    // POST (create) → PATCH (404) → POST (retry, exactly once).
    expect($configCalls)->toHaveCount(3);
    expect($configCalls[0][0]->method())->toBe('POST');
    expect($configCalls[1][0]->method())->toBe('PATCH');
    expect($configCalls[2][0]->method())->toBe('POST');
});

// ─── Rotate: delete-then-recreate the secret, re-point every configuration ────

test('rotate: DELETEs then POSTs the secret (never PATCH — secrets are immutable), and every bound configuration is re-pointed', function (): void {
    // The bare `/v1/secrets` URL is POSTed to TWICE across this test (the
    // initial create, then the rotate-recreate) — a plain `Http::fake()`
    // re-registration for the SAME pattern would leave the FIRST response
    // (`sec_old`) matching forever, so a sequence is the correct tool (same
    // stacking gotcha this file's other tests document).
    Http::fakeSequence('*heygen.com/v1/secrets')
        ->push(heygenSecretResponse('sec_old'), 200)
        ->push(heygenSecretResponse('sec_new'), 200);

    Http::fake(['*heygen.com/v1/llm-configurations' => Http::response(heygenConfigurationResponse('cfg_a'), 200)]);

    $model = registrarModel();
    $org = Organization::factory()->create();
    $credential = registrarCredentialForOrg($org->id);

    $templateA = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Rotate A', 'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
    ]));
    $templateB = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Rotate B', 'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
    ]));

    app(HeygenLlmRegistrar::class)->ensureConfiguration($templateA);
    app(HeygenLlmRegistrar::class)->ensureConfiguration($templateB);
    expect($credential->fresh()->heygen_secret_id)->toBe('sec_old');

    Http::fake([
        '*heygen.com/v1/secrets/sec_old' => Http::response([], 200),
        '*heygen.com/v1/llm-configurations/cfg_a' => Http::response(heygenConfigurationResponse('cfg_a'), 200),
    ]);

    $result = app(HeygenLlmRegistrar::class)->rotateSecret($credential->fresh());

    expect($result['status'])->toBe('synced');
    expect($credential->fresh()->heygen_secret_id)->toBe('sec_new');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v1/secrets/sec_old'));
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/v1/secrets')
        && $request['secret_value'] === 'sk-real-gemini-key');
    Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/v1/secrets'));

    // Both configurations re-pointed via PATCH — every one bound to this
    // credential, found via the (organization_id, llm_credential_id) index.
    $patchedConfigs = collect(Http::recorded())->filter(
        fn (array $pair): bool => $pair[0]->method() === 'PATCH' && str_contains($pair[0]->url(), '/v1/llm-configurations/')
    );

    expect($patchedConfigs)->toHaveCount(2);
    expect($patchedConfigs->every(fn (array $pair): bool => $pair[0]['secret_id'] === 'sec_new'))->toBeTrue();
});

// ─── Forget: unbind or destroy deletes the configuration and clears the column ─

test('forget: DELETEs the configuration and clears heygen_llm_configuration_id', function (): void {
    Http::fake([
        '*heygen.com/v1/secrets' => Http::response(heygenSecretResponse('sec_1'), 200),
        '*heygen.com/v1/llm-configurations' => Http::response(heygenConfigurationResponse('cfg_1'), 200),
    ]);

    $template = registrarHeygenTemplate();
    app(HeygenLlmRegistrar::class)->ensureConfiguration($template);
    expect($template->fresh()->heygen_llm_configuration_id)->toBe('cfg_1');

    Http::fake(['*heygen.com/v1/llm-configurations/cfg_1' => Http::response([], 200)]);

    app(HeygenLlmRegistrar::class)->forget($template->fresh());

    expect($template->fresh()->heygen_llm_configuration_id)->toBeNull();
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v1/llm-configurations/cfg_1'));
});

test('forget: a vendor DELETE failure still clears the column and never throws', function (): void {
    Http::fake([
        '*heygen.com/v1/secrets' => Http::response(heygenSecretResponse('sec_1'), 200),
        '*heygen.com/v1/llm-configurations' => Http::response(heygenConfigurationResponse('cfg_1'), 200),
    ]);

    $template = registrarHeygenTemplate();
    app(HeygenLlmRegistrar::class)->ensureConfiguration($template);

    Http::fake(['*heygen.com/v1/llm-configurations/cfg_1' => Http::response([], 500)]);

    app(HeygenLlmRegistrar::class)->forget($template->fresh());

    expect($template->fresh()->heygen_llm_configuration_id)->toBeNull();
});

test('an unbound template is skipped and any stale configuration is forgotten', function (): void {
    Http::fake([
        '*heygen.com/v1/secrets' => Http::response(heygenSecretResponse('sec_1'), 200),
        '*heygen.com/v1/llm-configurations' => Http::response(heygenConfigurationResponse('cfg_1'), 200),
    ]);

    $template = registrarHeygenTemplate();
    app(HeygenLlmRegistrar::class)->ensureConfiguration($template);

    $template->forceFill(['llm_model_id' => null, 'llm_credential_id' => null])->saveQuietly();

    Http::fake(['*heygen.com/v1/llm-configurations/cfg_1' => Http::response([], 200)]);

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration($template->fresh());

    expect($result['status'])->toBe('skipped');
    expect($template->fresh()->heygen_llm_configuration_id)->toBeNull();
});

// ─── Secret containment ────────────────────────────────────────────────────

test('the Gemini key appears in no response, no exception, and no log channel across the full lifecycle', function (): void {
    $geminiKey = 'GEMINI_KEY_MUST_NOT_LEAK_ANYWHERE_HEYGEN';
    $model = registrarModel();
    $org = Organization::factory()->create();
    $credential = TenantContextScope::runFor($org->id, function () use ($org, $geminiKey): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $org->id,
            'name' => 'Secret containment credential',
            'vendor' => 'google',
            'api_key' => $geminiKey,
            'key_last_four' => substr($geminiKey, -4),
            'key_fingerprint' => hash('sha256', $geminiKey),
        ]);
        $c->save();

        return $c;
    });
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Secret containment template',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    // Worst case: the vendor ECHOES the key back in an error body.
    Http::fake(['*heygen.com*' => Http::response(['message' => "Unauthorized: {$geminiKey}"], 401)]);

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        $msg = $message->message ?? '';
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $logMessages[] = $msg.' '.$context;
    });

    $result = app(HeygenLlmRegistrar::class)->ensureConfiguration($template);

    expect($result['status'])->toBe('warning');
    expect(json_encode($result))->not->toContain($geminiKey);

    foreach ($logMessages as $logMsg) {
        expect($logMsg)->not->toContain($geminiKey);
    }
});
