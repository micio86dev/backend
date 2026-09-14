<?php

declare(strict_types=1);

/**
 * `LlmCredentialController::update()` (rotate) and `destroy()` call
 * `HeygenLlmRegistrar` so nothing is orphaned on the vendor side
 * (pluggable-conversation-llm PR P5, design D8).
 *
 * REQ: conversation-llm "Rotating a credential recreates its secret and
 *      patches every bound configuration"
 */

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function heygenLifecycleModel(): LlmModel
{
    return LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
        'text_input_usd_per_million' => '0.075000',
        'text_output_usd_per_million' => '0.300000',
    ]);
}

test('rotating a credential that already has a HeyGen secret deletes and recreates it, and re-points every bound configuration', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    heygenLifecycleModel();

    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate with HeyGen',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    $credential = LlmCredential::withoutGlobalScopes()->find($created->json('data.id'));
    // Simulate a PRIOR HeyGen bind having already registered a secret — the
    // vendor call itself is PR P5's `HeygenLlmRegistrar`'s own concern, not
    // this controller's; this test only proves the WIRING.
    $credential->forceFill(['heygen_secret_id' => 'sec_old'])->saveQuietly();

    $model = LlmModel::where('key', 'gemini-3-flash-preview')->firstOrFail();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Heygen rotate template',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill(['heygen_llm_configuration_id' => 'cfg_old'])->saveQuietly();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
        '*liveavatar.com/v1/secrets/sec_old' => Http::response([], 200),
        '*liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec_new'], 'message' => 'ok'], 200),
        '*liveavatar.com/v1/llm-configurations/cfg_old' => Http::response(['data' => ['id' => 'cfg_old']], 200),
    ]);

    $this->withToken($token)->patchJson('/api/llm-credentials/'.$credential->id, [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200);

    expect($credential->fresh()->heygen_secret_id)->toBe('sec_new');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v1/secrets/sec_old'));
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/v1/llm-configurations/cfg_old')
        && $request['secret_id'] === 'sec_new');
    Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/v1/secrets'));
});

test('rotating a credential that has never been used with HeyGen makes no HeyGen call at all', function (): void {
    heygenLifecycleModel();

    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate without HeyGen',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    // No heygen_secret_id was ever set — this credential has never been
    // bound to a HeyGen template. Eagerly registering a secret here would
    // risk an ORPHAN (design D8: secret_name is not unique on the vendor
    // side), so the registrar must not be called at all.
    $this->withToken($token)->patchJson('/api/llm-credentials/'.$created->json('data.id'), [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200);

    // Asserted on the host the registrar ACTUALLY calls. Matching on
    // 'heygen.com' — as this line did before the host was corrected — would
    // now hold no matter what the registrar did, and a test that cannot fail
    // is worse than no test: it reads as coverage this behaviour does not have.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'liveavatar.com'));
});

test('deleting an unbound credential with a HeyGen secret deletes the vendor secret and clears the id', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $credential = TenantContextScope::runFor($org->id, function (): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'name' => 'Delete with HeyGen secret',
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
            'heygen_secret_id' => 'sec_to_delete',
        ]);
        $c->save();

        return $c;
    });

    Http::fake(['*liveavatar.com/v1/secrets/sec_to_delete' => Http::response([], 200)]);

    $this->withToken($token)->deleteJson("/api/llm-credentials/{$credential->id}")
        ->assertStatus(200);

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v1/secrets/sec_to_delete'));
});

test('deleting a bound HeyGen template deletes its vendor configuration — no orphan is left billing the credential', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    $model = heygenLifecycleModel();

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $credential = TenantContextScope::runFor($org->id, function (): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'name' => 'Template delete credential',
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
            'heygen_secret_id' => 'sec_live',
        ]);
        $c->save();

        return $c;
    });

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Template to delete',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill(['heygen_llm_configuration_id' => 'cfg_live'])->saveQuietly();

    Http::fake(['*liveavatar.com/v1/llm-configurations/cfg_live' => Http::response([], 200)]);

    $this->withToken($token)->deleteJson("/api/avatar-templates/{$template->id}")
        ->assertStatus(204);

    // The vendor-side configuration is the thing that would keep billing;
    // deleting OUR row without deleting THEIRS is the orphan design D8 makes
    // `heygen_llm_configuration_id` the ledger for.
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v1/llm-configurations/cfg_live'));

    // `withoutGlobalScopes()` would drop the SOFT-DELETE scope along with the
    // tenant one and find the trashed row, asserting nothing. Only the tenant
    // scope is dropped, so this still means "gone as far as anyone can see".
    expect(AvatarTemplate::withoutGlobalScope('tenant')->find($template->id))->toBeNull();

    // And gone WITHOUT leaving a stale binding behind. The row survives a soft
    // delete, and a surviving `llm_credential_id` is what the credential's own
    // deletion guard counts — a template nobody can see would otherwise refuse
    // a credential deletion forever, or worse, let it through into a raw
    // foreign-key 500.
    $trashed = AvatarTemplate::withoutGlobalScope('tenant')->withTrashed()->find($template->id);
    expect($trashed?->trashed())->toBeTrue()
        ->and($trashed?->llm_credential_id)->toBeNull()
        ->and($trashed?->llm_model_id)->toBeNull();
});

test('deleting a HeyGen template whose vendor account is unreachable still deletes the template', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    $model = heygenLifecycleModel();

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');

    $credential = TenantContextScope::runFor($org->id, function (): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'name' => 'Unreachable vendor credential',
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
            'heygen_secret_id' => 'sec_live',
        ]);
        $c->save();

        return $c;
    });

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Template to delete anyway',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill(['heygen_llm_configuration_id' => 'cfg_live'])->saveQuietly();

    Http::fake(fn () => throw new ConnectionException('down'));

    // An unreachable HeyGen account must never block an operator from
    // deleting their OWN template (design D8, "never throws").
    $this->withToken($token)->deleteJson("/api/avatar-templates/{$template->id}")
        ->assertStatus(204);

    // `withoutGlobalScopes()` would drop the SOFT-DELETE scope along with the
    // tenant one and find the trashed row, asserting nothing. Only the tenant
    // scope is dropped, so this still means "gone as far as anyone can see".
    expect(AvatarTemplate::withoutGlobalScope('tenant')->find($template->id))->toBeNull();

    // And gone WITHOUT leaving a stale binding behind. The row survives a soft
    // delete, and a surviving `llm_credential_id` is what the credential's own
    // deletion guard counts — a template nobody can see would otherwise refuse
    // a credential deletion forever, or worse, let it through into a raw
    // foreign-key 500.
    $trashed = AvatarTemplate::withoutGlobalScope('tenant')->withTrashed()->find($template->id);
    expect($trashed?->trashed())->toBeTrue()
        ->and($trashed?->llm_credential_id)->toBeNull()
        ->and($trashed?->llm_model_id)->toBeNull();
});

/**
 * A rotation whose re-push FAILS must not leave the template claiming to be
 * synced — that claim is a billing assertion, not a status badge.
 *
 * `LlmBindingResolver::resolveStatus()` reads `llm_sync_status === 'synced'`
 * as `Applied`, the state its own docblock calls the only BILLABLE one. Before
 * `rotateSecret()` wrote the status itself, a failed re-push left every
 * affected template on `synced` — asserting a live billable binding to a
 * vendor secret `forgetSecret()` had deleted moments earlier.
 *
 * The controller discarded `rotateSecret()`'s return value, and rotation does
 * not pass through `AvatarTemplateController::recordSync()`, which is the only
 * other place that writes this column. Nothing wrote it, so nothing was wrong
 * to see.
 */
test('a rotation whose re-push fails marks bound templates failed, never left claiming synced', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    heygenLifecycleModel();

    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate that fails',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    $credential = LlmCredential::query()->find($created->json('data.id'));
    $credential->forceFill(['heygen_secret_id' => 'sec_old'])->saveQuietly();

    $model = LlmModel::where('key', 'gemini-3-flash-preview')->firstOrFail();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Template that goes stale',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    // The state this test exists to break: a template the system believes is
    // live and billable.
    $template->forceFill([
        'heygen_llm_configuration_id' => 'cfg_old',
        'llm_sync_status' => 'synced',
        'llm_synced_at' => now(),
    ])->saveQuietly();

    // The secret is destroyed and recreated fine; the CONFIGURATION re-push is
    // what fails — the exact shape that used to go unrecorded.
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
        '*liveavatar.com/v1/secrets/sec_old' => Http::response([], 200),
        '*liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec_new'], 'message' => 'ok'], 200),
        '*liveavatar.com/v1/llm-configurations/*' => Http::response([], 500),
        '*liveavatar.com/v1/llm-configurations' => Http::response([], 500),
    ]);

    $this->withToken($token)->patchJson('/api/llm-credentials/'.$credential->id, [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200);

    $fresh = $template->fresh();

    expect($fresh->llm_sync_status)->toBe('failed');
    expect($fresh->llm_synced_at)->toBeNull();
});

test('a rotation that succeeds marks bound templates synced', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    heygenLifecycleModel();

    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate that works',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    $credential = LlmCredential::query()->find($created->json('data.id'));
    $credential->forceFill(['heygen_secret_id' => 'sec_old'])->saveQuietly();

    $model = LlmModel::where('key', 'gemini-3-flash-preview')->firstOrFail();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Template that stays live',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill(['heygen_llm_configuration_id' => 'cfg_old'])->saveQuietly();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
        '*liveavatar.com/v1/secrets/sec_old' => Http::response([], 200),
        '*liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec_new'], 'message' => 'ok'], 200),
        '*liveavatar.com/v1/llm-configurations/cfg_old' => Http::response(['data' => ['id' => 'cfg_old']], 200),
    ]);

    $this->withToken($token)->patchJson('/api/llm-credentials/'.$credential->id, [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200);

    expect($template->fresh()->llm_sync_status)->toBe('synced');
});

/**
 * The WORSE rotation failure: the new secret is never created at all.
 *
 * `forgetSecret()` has already destroyed the old secret AND nulled
 * `heygen_secret_id` by the time `ensureSecret()` fails, and
 * `LlmCredentialController::update()` only calls `rotateSecret()` when that
 * column is non-null — so no later save ever revisits these rows. Whatever
 * status they carry when this returns, they carry forever.
 *
 * An earlier fix stamped the status only inside the re-push loop, which this
 * path returns before reaching. It covered "new secret exists, config push
 * failed" and left "no new secret at all" reading `synced` — a permanent
 * `Applied`, which `LlmBindingResolver::resolveStatus()` calls billable,
 * against a vendor secret that no longer exists.
 */
test('a rotation that cannot create the new secret still marks bound templates failed', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    heygenLifecycleModel();

    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate with no new secret',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    $credential = LlmCredential::query()->find($created->json('data.id'));
    $credential->forceFill(['heygen_secret_id' => 'sec_old'])->saveQuietly();

    $model = LlmModel::where('key', 'gemini-3-flash-preview')->firstOrFail();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Template stranded by rotation',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill([
        'heygen_llm_configuration_id' => 'cfg_old',
        'llm_sync_status' => 'synced',
        'llm_synced_at' => now(),
    ])->saveQuietly();

    // The DELETE of the old secret succeeds; the CREATE of the new one does
    // not. That is the ordering that strands the templates.
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
        '*liveavatar.com/v1/secrets/sec_old' => Http::response([], 200),
        '*liveavatar.com/v1/secrets' => Http::response([], 500),
    ]);

    $this->withToken($token)->patchJson('/api/llm-credentials/'.$credential->id, [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200);

    $fresh = $template->fresh();

    expect($fresh->llm_sync_status)->toBe('failed');
    expect($fresh->llm_synced_at)->toBeNull();
});

/**
 * A rotation that DESTROYS the old secret and cannot create a new one must say so.
 *
 * `forgetSecret()` nulls `heygen_secret_id` whether or not the vendor call
 * succeeded — that is its documented NEVER-THROWS contract — so when
 * `ensureSecret()` then fails the credential is left with no secret at all and
 * every bound configuration references something deleted.
 *
 * The controller used to discard `rotateSecret()`'s return value and answer a
 * bare 200, telling the operator a rotation worked. `AvatarTemplateController::
 * recordSync()` states the doctrine this restores: an operator who is not told
 * will believe the setting took effect.
 */
test('a rotation that cannot recreate the secret answers with a warning, not a bare 200', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    heygenLifecycleModel();

    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate that loses its secret',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    $credential = LlmCredential::query()->find($created->json('data.id'));
    $credential->forceFill(['heygen_secret_id' => 'sec_old'])->saveQuietly();

    // The DELETE lands, the CREATE does not.
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
        '*liveavatar.com/v1/secrets/sec_old' => Http::response([], 200),
        '*liveavatar.com/v1/secrets' => Http::response([], 500),
    ]);

    $this->withToken($token)->patchJson('/api/llm-credentials/'.$credential->id, [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200)
        ->assertJsonPath('warning', 'llm_secret_failed');

    // And the row records the destruction rather than pretending it has a secret.
    expect($credential->fresh()->heygen_secret_id)->toBeNull();
});

test('a rotation that succeeds carries NO warning key', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');
    heygenLifecycleModel();

    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'platform');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

    $created = $this->withToken($token)->postJson('/api/llm-credentials', [
        'name' => 'Rotate cleanly',
        'vendor' => 'google',
        'api_key' => 'sk-old-key',
    ])->assertStatus(201);

    $credential = LlmCredential::query()->find($created->json('data.id'));
    $credential->forceFill(['heygen_secret_id' => 'sec_old'])->saveQuietly();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([], 200),
        '*liveavatar.com/v1/secrets/sec_old' => Http::response([], 200),
        '*liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec_new'], 'message' => 'ok'], 200),
    ]);

    $this->withToken($token)->patchJson('/api/llm-credentials/'.$credential->id, [
        'api_key' => 'sk-new-key-1234',
    ])->assertStatus(200)
        ->assertJsonMissingPath('warning');
});
