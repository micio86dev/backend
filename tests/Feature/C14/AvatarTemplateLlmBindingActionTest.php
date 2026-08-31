<?php

declare(strict_types=1);

/**
 * Binding/unbinding a template via `PATCH /avatar-templates/{id}`
 * (pluggable-conversation-llm PR P3a, design D3/D4).
 *
 * REQ: avatar-templates "Unbinding a template clears only that template's
 *      binding"
 */

use App\Models\AuditLog;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Http;

function bindActionModel(): LlmModel
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

function bindActionCredential(int $orgId): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $orgId,
            'name' => 'Bind action credential',
            'vendor' => 'google',
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $c->save();

        return $c;
    });
}

test('unbinding a HeyGen template clears its heygen_llm_configuration_id', function (): void {
    config()->set('interview.heygen.api_key', 'platform-heygen-key');

    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = bindActionModel();
    $credential = bindActionCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'HeyGen bound',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));
    $template->forceFill(['heygen_llm_configuration_id' => 'hg-config-1'])->saveQuietly();

    // PR P5: unbind now issues a REAL `DELETE /v1/llm-configurations/{id}`
    // via `HeygenLlmRegistrar::forget()`, not just a column clear — this
    // fake proves it, closing the gap the pre-P5 stub's own docblock named
    // ("the full lifecycle is wired in PR P5's HeygenLlmRegistrar").
    Http::fake(['*liveavatar.com/v1/llm-configurations/hg-config-1' => Http::response([], 200)]);

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", [
        'llm_model_id' => null,
        'llm_credential_id' => null,
    ])->assertStatus(200);

    $fresh = $template->fresh();
    expect($fresh->llm_model_id)->toBeNull();
    expect($fresh->llm_credential_id)->toBeNull();
    expect($fresh->heygen_llm_configuration_id)->toBeNull();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v1/llm-configurations/hg-config-1'));
});

test('binding a template is audited as avatar_template.llm_bound with names, never ids', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = bindActionModel();
    $credential = bindActionCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'To be bound',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", [
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(200);

    $log = AuditLog::withoutGlobalScopes()->where('action', 'avatar_template.llm_bound')->first();

    expect($log)->not->toBeNull();
    expect($log->after['model_key'])->toBe('gemini-3-flash-preview');
    expect($log->after['credential_name'])->toBe('Bind action credential');
    expect(json_encode($log->after))->not->toContain((string) $model->id);
    expect(json_encode($log->after))->not->toContain('sk-real-key');
});

test('unbinding a template is audited as avatar_template.llm_unbound with no key at any depth', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');
    $model = bindActionModel();
    $credential = bindActionCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'To be unbound',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    $this->withToken($token)->patchJson("/api/avatar-templates/{$template->id}", [
        'llm_model_id' => null,
        'llm_credential_id' => null,
    ])->assertStatus(200);

    $log = AuditLog::withoutGlobalScopes()->where('action', 'avatar_template.llm_unbound')->first();

    expect($log)->not->toBeNull();
    expect($log->before['model_key'])->toBe('gemini-3-flash-preview');
    expect($log->before['credential_name'])->toBe('Bind action credential');
    expect(json_encode($log->before))->not->toContain('sk-real-key');
});
