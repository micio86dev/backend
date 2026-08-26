<?php

declare(strict_types=1);

/**
 * The LLM binding read surface on `avatar-templates` (pluggable-conversation-llm,
 * closing the gap between PR P3a, which added the write path, and PR P8, which
 * needs to read it back).
 *
 * `LlmModelResource` previously never exposed a numeric `id`, and
 * `AvatarTemplateResource` never exposed the binding at all — so a backoffice
 * form could accept `llm_model_id`/`llm_credential_id` as required-shape
 * integers on write, yet had no server-exposed value to submit for ANY model,
 * bound or not. This file pins the read surface those two resources now expose.
 *
 * REQ: conversation-llm "The model registry is global, upserted, and carries
 *      a per-request context pricing tier"
 * REQ: avatar-templates "A template may bind one conversation model and one
 *      credential, both or neither"
 */

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\LlmModelRegistrySeeder;

function bindingReadCredential(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $orgId,
            'name' => 'Extremely Secret Credential Name',
            'vendor' => 'google',
            'api_key' => 'sk-should-never-leak',
            'key_last_four' => 'leak',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

test('GET /api/llm-models exposes a numeric id for every row', function (): void {
    (new LlmModelRegistrySeeder)->run();
    $org = Organization::factory()->create();

    $response = $this->withToken(authTokenForRole($org, 'operator'))
        ->getJson('/api/llm-models')
        ->assertOk();

    foreach ($response->json('data') as $row) {
        expect($row)->toHaveKey('id');
        expect($row['id'])->toBeInt();
    }

    $expectedId = LlmModel::where('key', 'gemini-3.1-pro-preview')->firstOrFail()->id;
    $row = collect($response->json('data'))->firstWhere('key', 'gemini-3.1-pro-preview');
    expect($row['id'])->toBe($expectedId);
});

test('GET /api/avatar-templates/{id} reports null binding fields on an unbound template', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Unbound read template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    $this->withToken($token)->getJson("/api/avatar-templates/{$template->id}")
        ->assertOk()
        ->assertJsonPath('data.llm_model_id', null)
        ->assertJsonPath('data.llm_credential_id', null)
        ->assertJsonPath('data.llm_sync_status', null)
        ->assertJsonPath('data.llm_synced_at', null);
});

test('GET /api/avatar-templates/{id} reports the binding ids on a bound template', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview-read',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
    $credential = bindingReadCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Bound read template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill(['llm_sync_status' => 'synced', 'llm_synced_at' => now()])->saveQuietly();

    $response = $this->withToken($token)->getJson("/api/avatar-templates/{$template->id}")
        ->assertOk();

    $response->assertJsonPath('data.llm_model_id', $model->id);
    $response->assertJsonPath('data.llm_credential_id', $credential->id);
    $response->assertJsonPath('data.llm_sync_status', 'synced');
    expect($response->json('data.llm_synced_at'))->not->toBeNull();
});

test('a bound template never leaks anything from llm_credentials beyond its id', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview-guard',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
    $credential = bindingReadCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Credential guard template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    $response = $this->withToken($token)->getJson("/api/avatar-templates/{$template->id}")
        ->assertOk();

    $body = json_encode($response->json('data'));

    expect($body)->not->toContain('sk-should-never-leak');
    expect($body)->not->toContain('Extremely Secret Credential Name');
    expect($body)->not->toContain($credential->key_last_four);
    expect($response->json('data'))->not->toHaveKey('llmCredential');
    expect($response->json('data'))->not->toHaveKey('llm_credential');
});
