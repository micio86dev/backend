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
use Illuminate\Support\Facades\Http;

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

test('deleting a credential bound to two templates is refused 409, naming both, and makes no HeyGen call', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
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

    // PR P5: the 409 gate must be checked BEFORE `HeygenLlmRegistrar::forgetSecret()`
    // is ever reached — a request that is refused must not also delete the
    // vendor secret out from under the templates still bound to it.
    Http::fake();

    $response = $this->withToken($token)->deleteJson("/api/llm-credentials/{$credential->id}")
        ->assertStatus(409);

    expect($response->json('error'))->toBe('credential_in_use');
    expect($response->json('templates'))->toContain('Bound A', 'Bound B');
    expect(LlmCredential::withoutGlobalScopes()->find($credential->id))->not->toBeNull();
    Http::assertNothingSent();
});

test('unbinding one template leaves the sibling bound, and deletion then succeeds', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
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
    $token = authTokenForRole($org, 'platform');
    $credential = creditInUseCredential($org->id);

    $this->withToken($token)->deleteJson("/api/llm-credentials/{$credential->id}")
        ->assertStatus(200);

    expect(LlmCredential::withoutGlobalScopes()->find($credential->id))->toBeNull();
});

test('a SOFT-DELETED template no longer blocks deleting the credential it was bound to', function (): void {
    // The regression this exists for. `avatar_templates.llm_credential_id`
    // references `llm_credentials` with ON DELETE RESTRICT, and the guard
    // above counts bound templates to decide whether removing a credential is
    // safe. That count applies the soft-delete scope; the foreign key does
    // not. Once templates learned to soft-delete, the two disagreed — the
    // guard saw nothing, allowed the delete, and Postgres refused it as an
    // unhandled 500 with a raw constraint name in it.
    //
    // The template now clears its binding when it is soft-deleted, so the two
    // agree again. Asserted end to end rather than on the hook, because what
    // broke was the INTERACTION and a unit test of either half would have
    // stayed green throughout.
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = creditInUseModel();
    $credential = creditInUseCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Bound then deleted',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    $this->withToken($token)->deleteJson("/api/avatar-templates/{$template->id}")
        ->assertStatus(204);

    $this->withToken($token)->deleteJson("/api/llm-credentials/{$credential->id}")
        ->assertOk();

    expect(LlmCredential::withoutGlobalScope('tenant')->find($credential->id))->toBeNull();
});
