<?php

declare(strict_types=1);

/**
 * `llm_sync_status`/`llm_synced_at` persistence around a HeyGen configuration
 * sync (pluggable-conversation-llm PR P5, design D0/D8) — the HeyGen mirror
 * of `TavusSyncStatePersistenceTest.php` (PR P4).
 *
 * `HeygenLlmRegistrar::ensureConfiguration()` persists
 * `heygen_llm_configuration_id` itself (it IS the orphan ledger, design D8),
 * but NOT `llm_sync_status`/`llm_synced_at` — those are written by
 * `AvatarTemplateController::recordSync()`, exactly as they are for Tavus, so
 * `LlmBindingResolver::resolveStatus()` stays ONE rule for both providers.
 *
 * REQ: conversation-llm "A failed provider sync is recorded, not just
 *      reported, so a later session resolves `degraded` and is never billed"
 */

use App\Enums\LlmBindingStatus;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Services\ConversationLlm\LlmBindingResolver;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Http;

function heygenSyncStateModel(): LlmModel
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

function heygenSyncStateCredential(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $orgId,
            'name' => 'Heygen-sync-state-cred-'.uniqid(),
            'vendor' => 'google',
            'api_key' => 'sk-real-gemini-key',
            'key_last_four' => 'lkey',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

test('a failed HeyGen configuration sync persists llm_sync_status !== synced, and a later session resolve is degraded', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = heygenSyncStateModel();
    $credential = heygenSyncStateCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Heygen sync-state template',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    // The vendor's own real error shape does not matter to this test — any
    // non-2xx across the secret/configuration lifecycle is a sync failure.
    Http::fake(['*heygen.com*' => Http::response(['message' => 'nope'], 500)]);

    $this->withToken($token)
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Heygen sync-state — renamed'])
        ->assertSuccessful();

    $fresh = $template->fresh();
    expect($fresh->llm_sync_status)->toBe('failed');
    expect($fresh->llm_synced_at)->toBeNull();

    // The D0 tri-state decision, read from PERSISTED state only.
    expect(app(LlmBindingResolver::class)->resolveStatus($fresh))->toBe(LlmBindingStatus::Degraded);
});

test('a successful HeyGen configuration sync persists llm_sync_status = synced, and a later session resolve is applied', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = heygenSyncStateModel();
    $credential = heygenSyncStateCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Heygen sync-state ok',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    Http::fake([
        '*heygen.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec_ok'], 'message' => 'ok'], 200),
        '*heygen.com/v1/llm-configurations' => Http::response(['data' => ['id' => 'cfg_ok']], 200),
    ]);

    $this->withToken($token)
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Heygen sync-state ok — renamed'])
        ->assertSuccessful();

    $fresh = $template->fresh();
    expect($fresh->llm_sync_status)->toBe('synced');
    expect($fresh->llm_synced_at)->not->toBeNull();
    expect($fresh->heygen_llm_configuration_id)->toBe('cfg_ok');
    expect(app(LlmBindingResolver::class)->resolveStatus($fresh))->toBe(LlmBindingStatus::Applied);
});
