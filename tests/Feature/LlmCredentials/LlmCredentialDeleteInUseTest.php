<?php

declare(strict_types=1);

/**
 * `DELETE /llm-credentials/{id}` on a bound credential is refused
 * (pluggable-conversation-llm PR P3a, design D2, non-negotiable #14).
 *
 * Precedent: `AvatarTemplateController::destroy()`'s 409 `template_active`.
 * The `(organization_id, llm_credential_id)` index (design D3) is what
 * makes the bound-template lookup one query.
 *
 * REQ: conversation-llm "A credential in use cannot be deleted; unbinding
 *      is a separate, narrower action"
 */

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creditInUseModel(): LlmModel
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

function creditInUseCredential(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $orgId,
            'name' => 'Bound credential',
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $c->save();

        return $c;
    });
}

test('deleting a credential bound to two templates is refused 409, naming both', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = creditInUseModel();
    $credential = creditInUseCredential($org->id);

    TenantContextScope::runFor($org->id, function () use ($model, $credential): void {
        AvatarTemplate::create([
            'name' => 'Bound A', 'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
        ]);
        AvatarTemplate::create([
            'name' => 'Bound B', 'provider' => 'heygen',
            'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
            'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
        ]);
    });

    $response = $this->withToken($token)->deleteJson("/api/llm-credentials/{$credential->id}")
        ->assertStatus(409);

    expect($response->json('error'))->toBe('credential_in_use');
    expect($response->json('templates'))->toContain('Bound A', 'Bound B');
    expect(LlmCredential::withoutGlobalScopes()->find($credential->id))->not->toBeNull();
});

test('unbinding one template leaves the sibling bound, and deletion then succeeds', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = creditInUseModel();
    $credential = creditInUseCredential($org->id);

    $templateA = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Bound A', 'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
    ]));
    $templateB = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Bound B', 'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id, 'llm_credential_id' => $credential->id,
    ]));

    $this->withToken($token)->patchJson("/api/avatar-templates/{$templateA->id}", [
        'llm_model_id' => null,
        'llm_credential_id' => null,
    ])->assertStatus(200);

    expect($templateB->fresh()->llm_credential_id)->toBe($credential->id);

    $this->withToken($token)->deleteJson("/api/avatar-templates/{$templateB->id}");

    $this->withToken($token)->deleteJson("/api/llm-credentials/{$credential->id}")
        ->assertStatus(200);
});

test('an unbound credential deletes with 200', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $credential = creditInUseCredential($org->id);

    $this->withToken($token)->deleteJson("/api/llm-credentials/{$credential->id}")
        ->assertStatus(200);

    expect(LlmCredential::withoutGlobalScopes()->find($credential->id))->toBeNull();
});
