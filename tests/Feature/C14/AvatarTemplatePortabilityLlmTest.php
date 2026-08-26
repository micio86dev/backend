<?php

declare(strict_types=1);

/**
 * Portability export/import of a template's LLM binding
 * (pluggable-conversation-llm PR P3b, design D13).
 *
 * The binding travels by NAME, never by id — `{model_key, credential_name}`
 * only, resolved against the IMPORTING organization, both-or-neither. I1's
 * CHECK makes "resolve what you can" a shape the database refuses to store,
 * so the matrix has no partial row in it.
 *
 * REQ: avatar-templates "Portability export and import never carry a
 *      credential id or key"
 */

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Models\User;
use App\Support\AvatarTemplates\TemplateDocument;
use App\Support\Tenancy\TenantContextScope;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function portabilityLlmModel(string $key = 'gemini-3-flash-preview', string $capability = 'text'): LlmModel
{
    return LlmModel::firstOrCreate(['key' => $key], [
        'vendor' => 'google',
        'display_name' => $key,
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => $capability,
        'is_available' => true,
        'sort_order' => 0,
    ]);
}

function portabilityLlmCredential(int $orgId, string $name = 'Portable credential', string $vendor = 'google'): LlmCredential
{
    return TenantContextScope::runFor($orgId, function () use ($orgId, $name, $vendor): LlmCredential {
        $c = new LlmCredential;
        $c->forceFill([
            'organization_id' => $orgId,
            'name' => $name,
            'vendor' => $vendor,
            'api_key' => 'sk-real-key',
            'key_last_four' => 'real',
            'key_fingerprint' => hash('sha256', uniqid('', true)),
        ]);
        $c->save();

        return $c;
    });
}

function portabilityAdminToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]));

    return auth('api')->login($user);
}

// ─── Export ────────────────────────────────────────────────────────────────────

test('exporting a bound template carries model_key and credential_name only', function (): void {
    $org = Organization::factory()->create();
    $model = portabilityLlmModel();
    $credential = portabilityLlmCredential($org->id);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Bound export',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    $document = TenantContextScope::runFor($org->id, fn () => TemplateDocument::export(
        AvatarTemplate::query()->whereKey($template->id)->get()
    ));
    $llm = $document['templates'][0]['llm'];

    expect($llm)->toBe([
        'model_key' => 'gemini-3-flash-preview',
        'credential_name' => 'Portable credential',
    ]);
    expect(json_encode($document))->not->toContain('sk-real-key');
    expect($llm)->not->toHaveKey('llm_credential_id');
    expect($llm)->not->toHaveKey('id');
});

test('exporting an unbound template carries a null llm block', function (): void {
    $org = Organization::factory()->create();
    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Unbound export',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));

    $document = TenantContextScope::runFor($org->id, fn () => TemplateDocument::export(
        AvatarTemplate::query()->whereKey($template->id)->get()
    ));

    expect($document['templates'][0]['llm'])->toBeNull();
});

// ─── The four-cell resolution matrix ───────────────────────────────────────────

test('both model_key and credential_name resolve — the import is bound', function (): void {
    $importingOrg = Organization::factory()->create();
    $token = portabilityAdminToken($importingOrg);
    portabilityLlmModel();
    portabilityLlmCredential($importingOrg->id, 'My local key');

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => TemplateDocument::SCHEMA,
        'templates' => [[
            'name' => 'Imported bound',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm' => ['model_key' => 'gemini-3-flash-preview', 'credential_name' => 'My local key'],
        ]],
    ])->assertStatus(201);

    $created = TenantContextScope::runFor($importingOrg->id, fn () => AvatarTemplate::where('name', 'Imported bound')->first());
    expect($created->llm_model_id)->not->toBeNull();
    expect($created->llm_credential_id)->not->toBeNull();
});

test('an unresolvable credential_name imports unbound with a warning', function (): void {
    $importingOrg = Organization::factory()->create();
    $token = portabilityAdminToken($importingOrg);
    portabilityLlmModel();

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => TemplateDocument::SCHEMA,
        'templates' => [[
            'name' => 'Imported no credential',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm' => ['model_key' => 'gemini-3-flash-preview', 'credential_name' => 'does-not-exist'],
        ]],
    ])->assertStatus(201);

    expect($response->json('data.0.llm_warnings'))->toBe(['credential_not_found']);

    $created = TenantContextScope::runFor($importingOrg->id, fn () => AvatarTemplate::where('name', 'Imported no credential')->first());
    expect($created->llm_model_id)->toBeNull();
    expect($created->llm_credential_id)->toBeNull();
});

test('an unresolvable model_key imports unbound with a warning', function (): void {
    $importingOrg = Organization::factory()->create();
    $token = portabilityAdminToken($importingOrg);
    portabilityLlmCredential($importingOrg->id, 'Some key');

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => TemplateDocument::SCHEMA,
        'templates' => [[
            'name' => 'Imported no model',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm' => ['model_key' => 'does-not-exist', 'credential_name' => 'Some key'],
        ]],
    ])->assertStatus(201);

    expect($response->json('data.0.llm_warnings'))->toBe(['model_not_found']);

    $created = TenantContextScope::runFor($importingOrg->id, fn () => AvatarTemplate::where('name', 'Imported no model')->first());
    expect($created->llm_model_id)->toBeNull();
    expect($created->llm_credential_id)->toBeNull();
});

test('when both fail to resolve, the import is unbound with both warning codes', function (): void {
    $importingOrg = Organization::factory()->create();
    $token = portabilityAdminToken($importingOrg);

    $response = $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => TemplateDocument::SCHEMA,
        'templates' => [[
            'name' => 'Imported neither',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm' => ['model_key' => 'nope', 'credential_name' => 'nope'],
        ]],
    ])->assertStatus(201);

    expect($response->json('data.0.llm_warnings'))->toContain('model_not_found', 'credential_not_found');
});

test('an import naming a native_duplex model is rejected 422, not a warning', function (): void {
    $importingOrg = Organization::factory()->create();
    $token = portabilityAdminToken($importingOrg);
    portabilityLlmModel('gemini-3.1-flash-live-preview', 'native_duplex');
    portabilityLlmCredential($importingOrg->id, 'Native key');

    $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => TemplateDocument::SCHEMA,
        'templates' => [[
            'name' => 'Imported native duplex',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm' => ['model_key' => 'gemini-3.1-flash-live-preview', 'credential_name' => 'Native key'],
        ]],
    ])->assertStatus(422);

    expect(AvatarTemplate::where('name', 'Imported native duplex')->exists())->toBeFalse();
});

test('an import naming a vendor-mismatched credential is rejected 422, not a warning', function (): void {
    $importingOrg = Organization::factory()->create();
    $token = portabilityAdminToken($importingOrg);
    portabilityLlmModel();
    portabilityLlmCredential($importingOrg->id, 'Mismatched key', vendor: 'not-google');

    $this->withToken($token)->postJson('/api/avatar-templates/import', [
        'schema' => TemplateDocument::SCHEMA,
        'templates' => [[
            'name' => 'Imported vendor mismatch',
            'provider' => 'tavus',
            'config' => ['faceId' => 'f', 'palId' => 'p'],
            'llm' => ['model_key' => 'gemini-3-flash-preview', 'credential_name' => 'Mismatched key'],
        ]],
    ])->assertStatus(422);

    expect(AvatarTemplate::where('name', 'Imported vendor mismatch')->exists())->toBeFalse();
});

// ─── flatten() carries llm through both document shapes ───────────────────────

test('flatten carries llm through the BEAI single-provider shape', function (): void {
    $records = TemplateDocument::flatten([
        'name' => 'Single',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
        'llm' => ['model_key' => 'gemini-3-flash-preview', 'credential_name' => 'X'],
    ]);

    expect($records[0]['llm'])->toBe(['model_key' => 'gemini-3-flash-preview', 'credential_name' => 'X']);
});

test('flatten yields a null llm for the avatar-tester multi-provider shape', function (): void {
    $records = TemplateDocument::flatten([
        'name' => 'Multi',
        'configs' => ['heygen' => ['avatarId' => 'a', 'voiceId' => 'v'], 'tavus' => ['faceId' => 'f', 'palId' => 'p']],
    ]);

    foreach ($records as $record) {
        expect($record['llm'])->toBeNull();
    }
});
