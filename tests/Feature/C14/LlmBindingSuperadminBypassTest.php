<?php

declare(strict_types=1);

/**
 * Invariant I3 — the credential belongs to the template's org — compared
 * EXPLICITLY against an unscoped read, because the tenant global scope has a
 * documented superadmin bypass and is therefore not an authorization check
 * (pluggable-conversation-llm PR P3a, design D4, non-negotiable #1/#2 — the
 * gate blocker).
 *
 * `TenantScoped.php` returns from its scope closure with NO filter applied
 * when `TenantResolver::isBypass()` is true, and `TenantContext.php` grants
 * that bypass to any authenticated superadmin. The tamper-proof
 * `organization_id` re-stamp fires on `creating` ONLY — never on an UPDATE.
 * A superadmin editing an Org A template could therefore bind Org B's
 * `llm_credential_id`; that credential decrypts to a Gemini key POSTed to
 * Tavus on every PAL PATCH, so Org B would pay for Org A's interviews.
 *
 * Run through the REAL middleware stack (auth:api + TenantContext), not a
 * faked resolver — the bug lives in the interaction between the two.
 *
 * REQ: avatar-templates "A template may bind one conversation model and one
 *      credential, both or neither"
 */

use App\Exceptions\ConversationLlm\InvalidLlmBindingException;
use App\Http\Middleware\TenantContext;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;

function i3ManagedModel(): LlmModel
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

function i3CredentialForOrg(int $orgId): LlmCredential
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

/**
 * Establishes the REAL bypass state a superadmin request produces, by
 * running the REAL `TenantContext` middleware against a real `Request`
 * carrying a real superadmin user — not a faked resolver.
 *
 * Deliberate scope note: `AvatarTemplatePolicy::update()`'s
 * `hasRole('admin')` cannot be satisfied by ANY superadmin (org=null) user
 * through the full HTTP+Gate stack in this codebase — Spatie's teams-mode
 * `model_has_roles.team_id` column is NOT NULL (the package's own
 * migration, unmodified), so no role can ever be assigned "for no team",
 * and `TenantContext` unconditionally resets the ambient team id to `null`
 * on the bypass branch. That is an orthogonal, pre-existing RBAC gap — every
 * hasRole()-gated policy already refuses every superadmin request outright,
 * independent of I3 — and fixing it is out of this PR's scope. This test
 * therefore drives the REAL middleware + REAL TenantResolver + REAL
 * `AvatarTemplate::booted()` directly, which is exactly where D4's
 * vulnerability lives, without routing through the unrelated Gate wall.
 */
function i3TriggerSuperadminBypass(): void
{
    $superadmin = User::factory()->create([
        'organization_id' => null,
        'is_superadmin' => true,
    ]);

    $request = Request::create('/api/avatar-templates/1', 'PATCH');
    $request->setUserResolver(fn () => $superadmin);

    app(TenantContext::class)->handle($request, fn ($req) => response()->json([]));
}

test('the INSERT path derives the owning org from the resolver, before the creating stamp runs', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'platform');
    $model = i3ManagedModel();
    $credential = i3CredentialForOrg($org->id);

    // A normal tenant binding ITS OWN credential must succeed — this is the
    // control case proving the INSERT-path owner derivation
    // (TenantResolver::getOrgId(), since `saving` fires before `creating`'s
    // stamp) is correct, not merely permissive.
    $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'Own-org bound template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ])->assertStatus(201);

    expect(AvatarTemplate::where('name', 'Own-org bound template')->first()->llm_credential_id)
        ->toBe($credential->id);
});

test('a superadmin cannot bind an Org A template to an Org B credential — I3 under the real bypass', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $model = i3ManagedModel();
    $credentialB = i3CredentialForOrg($orgB->id);

    $templateA = TenantContextScope::runFor($orgA->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Org A template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    i3TriggerSuperadminBypass();

    expect(app(TenantResolver::class)->isBypass())->toBeTrue();
    expect(app(TenantResolver::class)->getOrgId())->toBeNull();

    expect(fn () => $templateA->update([
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credentialB->id,
    ]))->toThrow(InvalidLlmBindingException::class);

    expect($templateA->fresh()->llm_credential_id)->toBeNull();
});
