<?php

declare(strict_types=1);

/**
 * Binding invariants I2 (mode) and I4 (vendor) — enforced in
 * `AvatarTemplate::booted()` on `saving`, so `create`, `update`, AND
 * `forceFill()->save()` (the portability import path) are all guarded
 * identically (pluggable-conversation-llm PR P3a, design D4,
 * non-negotiable #2).
 *
 * `forceFill()` bypasses `$fillable`; it does NOT bypass model events —
 * `AvatarTemplatePortabilityController.php:161` is exactly this call shape.
 *
 * REQ: conversation-llm "Mode is derived from the bound model's capability,
 *      and native_duplex is refused at every write path"
 * REQ: avatar-templates "A template may bind one conversation model and one
 *      credential, both or neither"
 */

use App\Exceptions\ConversationLlm\InvalidLlmBindingException;
use App\Exceptions\ConversationLlm\UnsupportedLlmModeException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;

function llmBindingManagedModel(): LlmModel
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

function llmBindingNativeDuplexModel(): LlmModel
{
    return LlmModel::create([
        'key' => 'gemini-3.1-flash-live-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3.1 Flash Live Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'native_duplex',
        'is_available' => true,
        'sort_order' => 0,
    ]);
}

function llmBindingCredentialForOrg(int $orgId, string $vendor = 'google'): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId, $vendor): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $orgId,
            'name' => 'Cred-'.uniqid(),
            'vendor' => $vendor,
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

// ─── I2 — native_duplex is refused ────────────────────────────────────────────

test('a native_duplex model is rejected on create', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = llmBindingNativeDuplexModel();
    $credential = llmBindingCredentialForOrg($org->id);

    $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'Native duplex template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['llm_model_id' => 'mode_unsupported']);

    expect(AvatarTemplate::where('name', 'Native duplex template')->exists())->toBeFalse();
});

test('a native_duplex model is rejected on update, and the row is unchanged', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = llmBindingNativeDuplexModel();
    $credential = llmBindingCredentialForOrg($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Unbound tavus',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", [
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['llm_model_id' => 'mode_unsupported']);

    expect($template->fresh()->llm_model_id)->toBeNull();
});

test('a native_duplex model is rejected via forceFill()->save() — the portability import path', function (): void {
    $org = Organization::factory()->create();
    $model = llmBindingNativeDuplexModel();
    $credential = llmBindingCredentialForOrg($org->id);

    TenantContextScope::runFor($org->id, function () use ($org, $model, $credential): void {
        $template = new AvatarTemplate;
        $template->forceFill([
            'organization_id' => $org->id,
            'name' => 'Imported native duplex',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm_model_id' => $model->id,
            'llm_credential_id' => $credential->id,
            'is_active' => false,
        ]);

        expect(fn () => $template->save())->toThrow(UnsupportedLlmModeException::class);
    });
});

// ─── I4 — vendor mismatch is refused ──────────────────────────────────────────

test('a vendor mismatch between model and credential is rejected on create', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = llmBindingManagedModel();
    $credential = llmBindingCredentialForOrg($org->id, vendor: 'not-google');

    $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'Vendor mismatch template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['llm_credential_id' => 'vendor_mismatch']);
});

test('a vendor mismatch between model and credential is rejected on update', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = llmBindingManagedModel();
    $credential = llmBindingCredentialForOrg($org->id, vendor: 'not-google');

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Unbound tavus 2',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", [
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['llm_credential_id' => 'vendor_mismatch']);
});

test('a vendor mismatch is rejected via forceFill()->save() — the portability import path', function (): void {
    $org = Organization::factory()->create();
    $model = llmBindingManagedModel();
    $credential = llmBindingCredentialForOrg($org->id, vendor: 'not-google');

    TenantContextScope::runFor($org->id, function () use ($org, $model, $credential): void {
        $template = new AvatarTemplate;
        $template->forceFill([
            'organization_id' => $org->id,
            'name' => 'Imported vendor mismatch',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm_model_id' => $model->id,
            'llm_credential_id' => $credential->id,
            'is_active' => false,
        ]);

        expect(fn () => $template->save())->toThrow(InvalidLlmBindingException::class);
    });
});

// ─── Defensive branches: a nonexistent model id, and a missing tenant context ──

test('a nonexistent llm_model_id is rejected as model_not_found', function (): void {
    $org = Organization::factory()->create();
    $credential = llmBindingCredentialForOrg($org->id);

    TenantContextScope::runFor($org->id, function () use ($org, $credential): void {
        $template = new AvatarTemplate;
        $template->forceFill([
            'organization_id' => $org->id,
            'name' => 'Garbage model id',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm_model_id' => 999999,
            'llm_credential_id' => $credential->id,
            'is_active' => false,
        ]);

        expect(fn () => $template->save())->toThrow(InvalidLlmBindingException::class);
    });
});

test('binding with no tenant context established fails closed', function (): void {
    $model = llmBindingManagedModel();

    // No TenantContextScope here — the resolver carries no org, simulating a
    // console command / job that forgot to establish tenant context before
    // creating a tenant-scoped model with a binding.
    $template = new AvatarTemplate;
    $template->forceFill([
        'organization_id' => 1,
        'name' => 'No tenant context',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => 999999,
        'is_active' => false,
    ]);

    expect(fn () => $template->save())->toThrow(MissingTenantContextException::class);
});

// ─── A managed model binds successfully ───────────────────────────────────────

test('a managed-capability model binds successfully via PATCH', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = llmBindingManagedModel();
    $credential = llmBindingCredentialForOrg($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Bindable tavus',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", [
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(200);

    expect($template->fresh()->llm_model_id)->toBe($model->id);
});
