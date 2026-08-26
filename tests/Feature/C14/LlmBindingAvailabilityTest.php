<?php

declare(strict_types=1);

/**
 * Binding invariant I5 — a withdrawn (`is_available = false`) model cannot
 * be NEWLY bound, but a template already bound to it keeps resolving
 * (pluggable-conversation-llm, closing the adversarial-review GAP 1: no
 * write path enforced `is_available` at all).
 *
 * Gated on `isDirty('llm_model_id')` deliberately: a template already bound
 * to a model that later becomes unavailable MUST keep saving for unrelated
 * field changes (renaming it, changing its voice settings) — that is the
 * entire point of "mark unavailable, never delete" (design D1). Rejecting
 * every unrelated edit to a grandfathered template would be a worse bug
 * than the one this guard fixes.
 *
 * REQ: conversation-llm "A model absent from a re-run of the seed array
 *      becomes is_available = false rather than being deleted"
 * REQ: avatar-templates "A template may bind one conversation model and one
 *      credential, both or neither"
 */

use App\Exceptions\ConversationLlm\InvalidLlmBindingException;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;

function llmAvailabilityModel(bool $isAvailable): LlmModel
{
    return LlmModel::create([
        'key' => 'gemini-3-flash-preview-'.uniqid(),
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => $isAvailable,
        'sort_order' => 0,
    ]);
}

function llmAvailabilityCredentialForOrg(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $orgId,
            'name' => 'Cred-'.uniqid(),
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $credential->save();

        return $credential;
    });
}

// ─── I5 — a withdrawn model cannot be newly bound ─────────────────────────────

test('binding to an is_available=false model is rejected 422 with a stable code, and nothing is persisted', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = llmAvailabilityModel(isAvailable: false);
    $credential = llmAvailabilityCredentialForOrg($org->id);

    $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'Unavailable model template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['llm_model_id' => 'model_unavailable']);

    expect(AvatarTemplate::where('name', 'Unavailable model template')->exists())->toBeFalse();
});

test('binding to an is_available=false model is rejected 422 on update, and the row is unchanged', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = llmAvailabilityModel(isAvailable: false);
    $credential = llmAvailabilityCredentialForOrg($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Unbound tavus',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", [
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['llm_model_id' => 'model_unavailable']);

    expect($template->fresh()->llm_model_id)->toBeNull();
});

test('a template already bound to a model that later becomes unavailable can still be saved for an unrelated field change', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = llmAvailabilityModel(isAvailable: true);
    $credential = llmAvailabilityCredentialForOrg($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Grandfathered tavus',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    // The model is withdrawn AFTER the binding was made.
    $model->update(['is_available' => false]);

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", [
        'name' => 'Grandfathered tavus — renamed',
    ])->assertStatus(200);

    $fresh = $template->fresh();
    expect($fresh->name)->toBe('Grandfathered tavus — renamed');
    expect($fresh->llm_model_id)->toBe($model->id);
});

test('a withdrawn model is rejected via forceFill()->save() — the portability import path', function (): void {
    $org = Organization::factory()->create();
    $model = llmAvailabilityModel(isAvailable: false);
    $credential = llmAvailabilityCredentialForOrg($org->id);

    TenantContextScope::runFor($org->id, function () use ($org, $model, $credential): void {
        $template = new AvatarTemplate;
        $template->forceFill([
            'organization_id' => $org->id,
            'name' => 'Imported unavailable model',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm_model_id' => $model->id,
            'llm_credential_id' => $credential->id,
            'is_active' => false,
        ]);

        expect(fn () => $template->save())->toThrow(InvalidLlmBindingException::class);
    });

    expect(AvatarTemplate::where('name', 'Imported unavailable model')->exists())->toBeFalse();
});
